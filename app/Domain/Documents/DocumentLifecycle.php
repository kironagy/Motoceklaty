<?php

namespace App\Domain\Documents;

use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\ApplicationEvent;
use App\Models\CustomerAttribute;

/**
 * One place where an accepted document stops counting. Values that came
 * only from it stop counting too: a brother's ID card, once accepted, kept
 * its name and national ID on the application, and the customer's own card
 * was then rejected for not matching it - the customer was locked onto the
 * wrong document with no way out.
 */
class DocumentLifecycle
{
    public function supersede(ApplicationDocument $document, string $reason): void
    {
        if ($document->status !== 'accepted') {
            return;
        }

        $document->update(['status' => 'superseded']);

        $application = $document->application;

        // Values read from this document are withdrawn; values the customer
        // stated that this document merely confirmed stay, unconfirmed.
        $withdrawn = ApplicationData::where('application_id', $document->application_id)
            ->where('document_id', $document->id)->where('source', 'document')->pluck('field_key')->all();
        ApplicationData::where('application_id', $document->application_id)
            ->where('document_id', $document->id)->where('source', 'document')->delete();
        ApplicationData::where('application_id', $document->application_id)
            ->where('document_id', $document->id)->update(['document_id' => null]);

        if ($application) {
            $withdrawn = array_merge($withdrawn, CustomerAttribute::where('customer_id', $application->customer_id)
                ->where('document_id', $document->id)->where('source', 'document')->pluck('field_key')->all());
            CustomerAttribute::where('customer_id', $application->customer_id)
                ->where('document_id', $document->id)->where('source', 'document')->delete();
            CustomerAttribute::where('customer_id', $application->customer_id)
                ->where('document_id', $document->id)->update(['document_id' => null, 'verified_at' => null]);
        }

        ApplicationEvent::create([
            'application_id' => $document->application_id,
            'type' => 'document_superseded',
            'from_status' => $application?->status,
            'to_status' => $application?->status,
            'actor' => 'system',
            'data' => ['document_id' => $document->id, 'type' => $document->detected_type_key, 'reason' => $reason, 'withdrawn_fields' => array_values(array_unique($withdrawn))],
        ]);
    }
}
