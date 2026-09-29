The flow should work like this:

The moment the chat is loaded, it passes the `businessId` to the backend. The backend then uses the `businessId` to fetch the required data — services, attributes, `businessBio`, `businessNiche`, and campaign information like requestedService — and sets all of them in the global state.
