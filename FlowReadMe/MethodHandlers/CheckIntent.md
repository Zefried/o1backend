checkIntent() currently sirf ye return karta hai:

{
  "userRequestInfo": true,
  "pricingIntent": false,
  "credibilityIntent": false,
  "userProvidedInfo": false,
  "ResponseToQualification": false,
  "topicChange": false,
  "newServiceDemand": null
}

Example:
"Can I see your portfolio?"

checkIntent() bolega:
{
  "credibilityIntent": true
}

Phir handle() dekhta hai:
credibilityIntent = true
        ↓
handleCredibilityRequest()

checkIntent() ka kaam complete routing karna nahi, bas bahut quickly intent detect karke handle() ko batana hai ki kaunsa handler call karna chahiye.