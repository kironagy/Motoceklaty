# Instruction inventory (PRE-002)

Generated 2026-10-05 14:15:27 by `php artisan agent:instruction-inventory`. Read-only snapshot; the full texts are in `instruction-inventory.json` (row ids `INV-xxxx` are what INST-001 classifies).

## Row counts vs DB / code

| Source | Rows | Expected | Match |
|---|---|---|---|
| local agent_instruction_versions | 16 | 16 | yes |
| local business_memories | 21 | 21 | yes |
| local bot_lessons | 0 | 0 | yes |
| tool descriptions | 20 | 20 | yes |
| tool-result prose lines | 0 | 0 | yes |
| guard hints | 33 | 33 | yes |

## Rows

| ID | Source | Location | Kind | Chars | Note |
|---|---|---|---|---|---|
| INV-0001 | local_db | `agent_instruction_versions#1` | instruction_version | 33696 | v2.2.1 (inactive) |
| INV-0002 | local_db | `agent_instruction_versions#2` | instruction_version | 33703 | v2.2.2 (inactive) |
| INV-0003 | local_db | `agent_instruction_versions#3` | instruction_version | 14839 | v2.2.42 (inactive) |
| INV-0004 | local_db | `agent_instruction_versions#4` | instruction_version | 16375 | v2.2.43 (inactive) |
| INV-0005 | local_db | `agent_instruction_versions#6` | instruction_version | 10260 | v2.3.0 (inactive) |
| INV-0006 | local_db | `agent_instruction_versions#7` | instruction_version | 12020 | v2.4.0 (inactive) |
| INV-0007 | local_db | `agent_instruction_versions#8` | instruction_version | 12475 | v2.4.1 (inactive) |
| INV-0008 | local_db | `agent_instruction_versions#9` | instruction_version | 13028 | v2.4.2 (inactive) |
| INV-0009 | local_db | `agent_instruction_versions#10` | instruction_version | 13381 | v2.4.3 (inactive) |
| INV-0010 | local_db | `agent_instruction_versions#11` | instruction_version | 13429 | v2.4.4 (inactive) |
| INV-0011 | local_db | `agent_instruction_versions#12` | instruction_version | 9890 | v2.4.5 (inactive) |
| INV-0012 | local_db | `agent_instruction_versions#13` | instruction_version | 9958 | v2.4.6 (inactive) |
| INV-0013 | local_db | `agent_instruction_versions#14` | instruction_version | 10020 | v2.4.7 (inactive) |
| INV-0014 | local_db | `agent_instruction_versions#15` | instruction_version | 11069 | v2.4.8 (inactive) |
| INV-0015 | local_db | `agent_instruction_versions#16` | instruction_version | 11523 | v2.4.9 (inactive) |
| INV-0016 | local_db | `agent_instruction_versions#17` | instruction_version | 18536 | v4.1.0 (active) |
| INV-0017 | code | `resources/agent/instructions/agent.md` | instruction_file | 18544 |  |
| INV-0018 | local_db | `business_memories#1` | business_memory | 417 | sales_personality (active) |
| INV-0019 | local_db | `business_memories#2` | business_memory | 414 | pricing_conversation_rules (active) |
| INV-0020 | local_db | `business_memories#3` | business_memory | 338 | installment_provider_context (active) |
| INV-0021 | local_db | `business_memories#4` | business_memory | 600 | required_documents_baseline (active) |
| INV-0022 | local_db | `business_memories#5` | business_memory | 484 | address_requirements (active) |
| INV-0023 | local_db | `business_memories#6` | business_memory | 352 | employment_category_docs (active) |
| INV-0024 | local_db | `business_memories#7` | business_memory | 99 | business_owner_docs (active) |
| INV-0025 | local_db | `business_memories#8` | business_memory | 273 | freelance_profession_docs (active) |
| INV-0026 | local_db | `business_memories#9` | business_memory | 349 | delivery_driver_docs (active) |
| INV-0027 | local_db | `business_memories#10` | business_memory | 138 | taxi_microbus_docs (active) |
| INV-0028 | local_db | `business_memories#11` | business_memory | 119 | bank_statement_cases (active) |
| INV-0029 | local_db | `business_memories#12` | business_memory | 321 | restricted_professions_guidance (active) |
| INV-0030 | local_db | `business_memories#13` | business_memory | 131 | delayed_application_followup (active) |
| INV-0031 | local_db | `business_memories#14` | business_memory | 323 | review_data_before_submit (active) |
| INV-0032 | local_db | `business_memories#15` | business_memory | 316 | activity_document_cross_check (active) |
| INV-0033 | local_db | `business_memories#16` | business_memory | 74 | installment_payment_schedule (active) |
| INV-0034 | local_db | `business_memories#17` | business_memory | 669 | catalog_naming_and_grades (active) |
| INV-0035 | local_db | `business_memories#18` | business_memory | 230 | spec_explanation_style (active) |
| INV-0036 | local_db | `business_memories#19` | business_memory | 443 | policies_not_on_file (active) |
| INV-0037 | local_db | `business_memories#20` | business_memory | 582 | self_employed_vs_business_owner (active) |
| INV-0038 | local_db | `business_memories#21` | business_memory | 364 | self_employed_financing_cap (active) |
| INV-0039 | code | `tool:get_earlier_messages` | tool_description | 221 |  |
| INV-0040 | code | `tool:send_reply` | tool_description | 97 |  |
| INV-0041 | code | `tool:handoff_to_human` | tool_description | 259 |  |
| INV-0042 | code | `tool:search_motorcycles` | tool_description | 437 |  |
| INV-0043 | code | `tool:get_motorcycle_details` | tool_description | 381 |  |
| INV-0044 | code | `tool:lookup_motorcycle_specs_online` | tool_description | 410 |  |
| INV-0045 | code | `tool:send_motorcycle_images` | tool_description | 289 |  |
| INV-0046 | code | `tool:identify_motorcycle_from_image` | tool_description | 741 |  |
| INV-0047 | code | `tool:get_branch_information` | tool_description | 275 |  |
| INV-0048 | code | `tool:get_application_requirements` | tool_description | 389 |  |
| INV-0049 | code | `tool:get_installment_offer` | tool_description | 328 |  |
| INV-0050 | code | `tool:get_installment_options` | tool_description | 410 |  |
| INV-0051 | code | `tool:record_work_profile` | tool_description | 240 |  |
| INV-0052 | code | `tool:check_eligibility` | tool_description | 559 |  |
| INV-0053 | code | `tool:record_customer_data` | tool_description | 505 |  |
| INV-0054 | code | `tool:start_application` | tool_description | 235 |  |
| INV-0055 | code | `tool:update_application_selection` | tool_description | 181 |  |
| INV-0056 | code | `tool:withdraw_application` | tool_description | 159 |  |
| INV-0057 | code | `tool:submit_application` | tool_description | 507 |  |
| INV-0058 | code | `tool:process_document` | tool_description | 679 |  |
| INV-0059 | code | `AgentRunner::GUARD_HINTS[EMPTY_REPLY]` | guard_hint | 19 | EMPTY_REPLY |
| INV-0060 | code | `AgentRunner::GUARD_HINTS[GARBLED_TEXT]` | guard_hint | 57 | GARBLED_TEXT |
| INV-0061 | code | `AgentRunner::GUARD_HINTS[INTERNAL_KEY_IN_REPLY]` | guard_hint | 45 | INTERNAL_KEY_IN_REPLY |
| INV-0062 | code | `AgentRunner::GUARD_HINTS[PLACEHOLDER_IN_REPLY]` | guard_hint | 60 | PLACEHOLDER_IN_REPLY |
| INV-0063 | code | `AgentRunner::GUARD_HINTS[SUBMISSION_CLAIMED_NOT_DONE]` | guard_hint | 73 | SUBMISSION_CLAIMED_NOT_DONE |
| INV-0064 | code | `AgentRunner::GUARD_HINTS[RESUBMISSION_CLAIMED]` | guard_hint | 77 | RESUBMISSION_CLAIMED |
| INV-0065 | code | `AgentRunner::GUARD_HINTS[RESUBMISSION_PROMISED]` | guard_hint | 68 | RESUBMISSION_PROMISED |
| INV-0066 | code | `AgentRunner::GUARD_HINTS[DATA_CLAIMED_NOT_SAVED]` | guard_hint | 68 | DATA_CLAIMED_NOT_SAVED |
| INV-0067 | code | `AgentRunner::GUARD_HINTS[DATA_OVERCLAIMED]` | guard_hint | 46 | DATA_OVERCLAIMED |
| INV-0068 | code | `AgentRunner::GUARD_HINTS[DOCUMENT_CLAIMED_NOT_ACCEPTED]` | guard_hint | 77 | DOCUMENT_CLAIMED_NOT_ACCEPTED |
| INV-0069 | code | `AgentRunner::GUARD_HINTS[DOCUMENT_PHOTO_NOT_PROCESSED]` | guard_hint | 104 | DOCUMENT_PHOTO_NOT_PROCESSED |
| INV-0070 | code | `AgentRunner::GUARD_HINTS[WITHDRAWAL_CLAIMED_NOT_DONE]` | guard_hint | 76 | WITHDRAWAL_CLAIMED_NOT_DONE |
| INV-0071 | code | `AgentRunner::GUARD_HINTS[APPLICATION_CLAIMED_NOT_OPENED]` | guard_hint | 69 | APPLICATION_CLAIMED_NOT_OPENED |
| INV-0072 | code | `AgentRunner::GUARD_HINTS[APPLICATION_ALREADY_OPEN_CLAIMED]` | guard_hint | 73 | APPLICATION_ALREADY_OPEN_CLAIMED |
| INV-0073 | code | `AgentRunner::GUARD_HINTS[SELECTION_CLAIMED_NOT_SET]` | guard_hint | 64 | SELECTION_CLAIMED_NOT_SET |
| INV-0074 | code | `AgentRunner::GUARD_HINTS[COMPLETION_OVERCLAIMED]` | guard_hint | 54 | COMPLETION_OVERCLAIMED |
| INV-0075 | code | `AgentRunner::GUARD_HINTS[IMAGES_CLAIMED_NOT_SENT]` | guard_hint | 65 | IMAGES_CLAIMED_NOT_SENT |
| INV-0076 | code | `AgentRunner::GUARD_HINTS[IMAGES_CLAIMED_FOR_UNSENT_MODEL]` | guard_hint | 54 | IMAGES_CLAIMED_FOR_UNSENT_MODEL |
| INV-0077 | code | `AgentRunner::GUARD_HINTS[HANDOFF_CLAIMED_NOT_DONE]` | guard_hint | 58 | HANDOFF_CLAIMED_NOT_DONE |
| INV-0078 | code | `AgentRunner::GUARD_HINTS[HANDOFF_TIME_PROMISED]` | guard_hint | 63 | HANDOFF_TIME_PROMISED |
| INV-0079 | code | `AgentRunner::GUARD_HINTS[UNRECORDED_PROMISE]` | guard_hint | 79 | UNRECORDED_PROMISE |
| INV-0080 | code | `AgentRunner::GUARD_HINTS[STOCK_OR_CHECK_CLAIMED]` | guard_hint | 52 | STOCK_OR_CHECK_CLAIMED |
| INV-0081 | code | `AgentRunner::GUARD_HINTS[AVAILABILITY_PROMISE]` | guard_hint | 42 | AVAILABILITY_PROMISE |
| INV-0082 | code | `AgentRunner::GUARD_HINTS[UNVERIFIED_NUMBER]` | guard_hint | 96 | UNVERIFIED_NUMBER |
| INV-0083 | code | `AgentRunner::GUARD_HINTS[TOTAL_NOT_SOURCED]` | guard_hint | 44 | TOTAL_NOT_SOURCED |
| INV-0084 | code | `AgentRunner::GUARD_HINTS[PERCENT_DURATION_MISMATCH]` | guard_hint | 63 | PERCENT_DURATION_MISMATCH |
| INV-0085 | code | `AgentRunner::GUARD_HINTS[BRANCH_NOT_SOURCED]` | guard_hint | 51 | BRANCH_NOT_SOURCED |
| INV-0086 | code | `AgentRunner::GUARD_HINTS[MODEL_NOT_LOOKED_UP]` | guard_hint | 49 | MODEL_NOT_LOOKED_UP |
| INV-0087 | code | `AgentRunner::GUARD_HINTS[UNSOURCED_FINANCE_COMPANY]` | guard_hint | 47 | UNSOURCED_FINANCE_COMPANY |
| INV-0088 | code | `AgentRunner::GUARD_HINTS[AGE_NOT_CHECKED]` | guard_hint | 60 | AGE_NOT_CHECKED |
| INV-0089 | code | `AgentRunner::GUARD_HINTS[WORK_REFUSAL_NOT_SOURCED]` | guard_hint | 76 | WORK_REFUSAL_NOT_SOURCED |
| INV-0090 | code | `AgentRunner::GUARD_HINTS[REQUIRED_DOCUMENT_WAIVED]` | guard_hint | 60 | REQUIRED_DOCUMENT_WAIVED |
| INV-0091 | code | `AgentRunner::GUARD_HINTS[INTEREST_DENIED]` | guard_hint | 52 | INTEREST_DENIED |
| INV-0092 | code | `DocumentPipeline::classify` | side_call_prompt | 337 |  |
| INV-0093 | code | `DocumentPipeline::fillMissingFields` | side_call_prompt | 2806 |  |
| INV-0094 | code | `IdentifyMotorcycleFromImageTool::execute` | side_call_prompt | 7650 |  |
| INV-0095 | code | `LookupMotorcycleSpecsOnlineTool::prompt` | side_call_prompt | 1208 |  |
| INV-0096 | code | `AddressSplitter::split` | side_call_prompt | 3628 |  |
| INV-0097 | code | `SummarizeConversation::handle` | side_call_prompt | 2945 |  |
| INV-0098 | code | `GeminiVoiceTranscriber::PROMPT` | side_call_prompt | 227 |  |
| INV-0099 | code | `OpenAiVoiceTranscriber::PROMPT` | side_call_prompt | 178 |  |
| INV-0100 | code | `TeachingCoach::ask` | side_call_prompt | 3513 |  |
| INV-0101 | code | `resources/agent/instructions/coach.md` | side_call_prompt | 6063 |  |
| INV-0102 | code | `AgentRunner::keepGoingReply` | hard_coded_text | 1182 |  |
| INV-0103 | code | `AgentRunner::fallback` | hard_coded_text | 786 |  |
| INV-0104 | code | `FinancingCapPolicy::explanation` | hard_coded_text | 574 |  |
| INV-0105 | code | `SubmitApplicationTool::execute` | hard_coded_text | 1473 |  |
| INV-0106 | code | `GetApplicationRequirementsTool::execute` | hard_coded_text | 1667 |  |
| INV-0107 | config | `agent.fallback.message` | hard_coded_text | 48 |  |
| INV-0108 | config | `agent.handoff.waiting_message` | hard_coded_text | 54 |  |
| INV-0109 | config | `agent.installments.price_difference_explanation` | hard_coded_text | 284 |  |
