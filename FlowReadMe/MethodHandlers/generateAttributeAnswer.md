User Request
    ↓
identifyRequestedAttribute()
    ↓
Clear Attribute?
   ↙        ↘
 YES        NO
  ↓          ↓
Answer    Ambiguous Match?
              ↓
             YES
              ↓
Store pending_attribute_clarification
              ↓
Ask: "Portfolio ke baare mein puch rahe ho?"
              ↓
User confirms
              ↓
Use identified attribute
              ↓
generateAttributeAnswer()
              ↓
Fetch ai_contexts
              ↓
Generate Answer