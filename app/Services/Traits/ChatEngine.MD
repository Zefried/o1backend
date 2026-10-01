

### 🚀 Conversational Flow & Stability Improvements (Updates & Fixes)

* **Smart Questioning (Prevent Next Query):** Built a backend caching system (`eyeOnResponses`) that blocks the AI from asking consecutive follow-up questions, creating a natural, balanced conversation flow.
* **Contextual Chat Resume:** Refactored `handleWelcome` to detect prior chat history, allowing the AI to naturally welcome returning users and directly resume past topics.
* **API Latency & Timeout Fix:** Eliminated a 10-second API retry loop by increasing the background LLM `maxTokens` (from 50 to 128), preventing JSON truncation errors.
* **PHP Heredoc & TypeError Fixes:** Resolved a `500 Internal Server Error` (Heredoc indentation bug during prompt injection) and fixed a `json_decode` TypeError when parsing LLM response arrays.



