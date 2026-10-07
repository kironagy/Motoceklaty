<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiRequest;
use App\Agent\Providers\AiResponse;
use App\Agent\Runtime\AgentRunner;
use App\Agent\Runtime\TurnResultBuilder;
use App\Agent\Tools\RecordWorkProfileTool;
use App\Agent\Tools\ToolContext;
use App\Exceptions\TransientAiFailure;
use App\Models\AiTraceStep;
use App\Models\Application;
use Database\Seeders\AgentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rebuild ARCH-006 / ERR-002: a turn retried after a provider failure resumes, it does not redo its writes. */
class TurnResumeTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    /** Scripted answers; an exception in the script is thrown at that call. */
    private function provider(array $script): object
    {
        $provider = new class($script) implements AiProvider
        {
            public array $requests = [];

            public function __construct(private array $script)
            {
            }

            public function chat(AiRequest $request): AiResponse
            {
                $this->requests[] = $request;
                $next = array_shift($this->script) ?? throw new AiProviderException('script empty');

                return $next instanceof \Throwable ? throw $next : $next;
            }
        };
        $this->app->instance(AiProvider::class, $provider);
        $this->app->forgetInstance(AgentRunner::class);

        return $provider;
    }

    public function test_a_retry_after_start_application_does_not_open_a_second_application(): void
    {
        $this->runtimeLimits();
        $this->seed(AgentCatalogSeeder::class);
        $conversation = $this->conversation();
        $turn = $this->turnFor($conversation, 'انا موظف متأمن عليا وعايز اقدم');
        app(RecordWorkProfileTool::class)->execute(['evidence' => 'انا موظف متأمن عليا', 'occupation' => 'موظف', 'job_title' => 'محاسب', 'work_stated' => true,
            'customer_type' => 'employee', 'working_now' => 'yes', 'relation_to_workplace' => 'works_for_someone', 'insured' => 'yes'],
            new ToolContext($conversation->customer_id, $conversation->id, null, 0, 1, new TurnResultBuilder()));

        $start = ['id' => 'c1', 'name' => 'start_application', 'args' => ['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف متأمن عليا']];

        // attempt 1: the application opens, then the provider is down
        $this->provider([$this->reply([$start]), new AiProviderException('503 overloaded', retryable: true)]);

        try {
            app(AgentRunner::class)->run($turn);
            $this->fail('expected a transient failure');
        } catch (TransientAiFailure) {
        }

        $this->assertSame(1, Application::count());

        // attempt 2 (re-queued): the model is told what is done and only replies
        $retry = $this->provider([$this->sendReply('تمام، ابعتلي صورة البطاقة وش وضهر')]);
        $result = app(AgentRunner::class)->run($turn);

        $this->assertSame(['تمام، ابعتلي صورة البطاقة وش وضهر'], $result['messages']);
        $this->assertCount(1, $retry->requests);
        $first = json_encode($retry->requests[0]->contents, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('اتعمل خلاص في نفس الرسالة دي', $first);
        $this->assertStringContainsString('start_application', $first);
        $this->assertSame(1, Application::count());
        $this->assertSame(1, AiTraceStep::where('turn_id', $turn->id)->where('tool_name', 'start_application')->count());
    }

    public function test_the_same_call_on_retry_gets_the_stored_result_without_running_again(): void
    {
        $this->runtimeLimits();
        $this->seed(AgentCatalogSeeder::class);
        $conversation = $this->conversation();
        $turn = $this->turnFor($conversation, 'انا موظف متأمن عليا وعايز اقدم');
        app(RecordWorkProfileTool::class)->execute(['evidence' => 'انا موظف متأمن عليا', 'occupation' => 'موظف', 'job_title' => 'محاسب', 'work_stated' => true,
            'customer_type' => 'employee', 'working_now' => 'yes', 'relation_to_workplace' => 'works_for_someone', 'insured' => 'yes'],
            new ToolContext($conversation->customer_id, $conversation->id, null, 0, 1, new TurnResultBuilder()));
        $start = ['id' => 'c1', 'name' => 'start_application', 'args' => ['customer_type' => 'employee', 'customer_type_quote' => 'انا موظف متأمن عليا']];

        $this->provider([$this->reply([$start]), new AiProviderException('timeout', retryable: true)]);

        try {
            app(AgentRunner::class)->run($turn);
        } catch (TransientAiFailure) {
        }

        // the model calls it again anyway: no second row, no second application
        $this->provider([$this->reply([['id' => 'c2'] + $start]), $this->sendReply('تمام')]);
        app(AgentRunner::class)->run($turn);

        $this->assertSame(1, Application::count());
        $this->assertSame(1, AiTraceStep::where('turn_id', $turn->id)->where('tool_name', 'start_application')->count());
    }
}
