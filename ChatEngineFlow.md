USER MESSAGE
     ↓
ChatEngineService
     ↓
Load / Prepare Context
├── Business Context
├── Active Service
├── Chat History
└── Qualification State
     ↓
handle()
     ↓
checkIntent()
     ↓
┌──────────────────────────────────────┐
│        INTENT ROUTING PRIORITY       │
└──────────────────────────────────────┘
     ↓
1. ResponseToQualification?
     │
     ├── YES → handleQualificationReply()
     │              ↓
     │         acknowledge/update state
     │              ↓
     │           RESPONSE
     │
     └── NO
          ↓
2. pricingIntent?
     │
     ├── YES → handlePricingRequest()
     │              ↓
     │         Check Active Service
     │              ↓
     │         Fetch ALL ai_contexts
     │              ↓
     │         Pricing Knowledge
     │              +
     │         Collected User Data
     │              +
     │         Relevant Chat History
     │              ↓
     │         Pricing LLM
     │              ↓
     │           RESPONSE
     │
     └── NO
          ↓
3. userRequestInfo / topicChange?
     │
     ├── YES → handleUserRequestInfo()
     │              ↓
     │         Check Active Service
     │              ↓
     │         Identify Requested Attribute
     │              ↓
     │         Check Available DB Data
     │              ↓
     │         ┌─────────────────────┐
     │         │ Data available?     │
     │         └─────────────────────┘
     │             ↓ YES        ↓ NO
     │      Generate Answer   No-data /
     │                       General fallback
     │             ↓              ↓
     │             └────── RESPONSE ──────┘
     │
     └── NO
          ↓
4. FALLBACK
     ↓
handleFallback()
     ↓
Check Pending Questions
     ↓
Classify message
├── Business → handleUserRequestInfo()
├── Casual   → Casual Response
└── Closing  → handleClosing()
     ↓
   RESPONSE
     ↓
FRONTEND / USER

User Message
     ↓
Understand intent
     ↓
Choose the correct handler
     ↓
Handler gets the right context/data
     ↓
LLM generates response
     ↓
User