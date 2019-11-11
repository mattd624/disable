# disable

The net effect is to disable the internet connection for a customer based on their statically assigned IP address. This is accomplished by:
1. a workflow rule in Salesforce that triggers an outbound message when certain fields are changed. 
2. an outbound message rule in Salesforce that contains data from certain fields.
3. the "listener", a box that runs apache and php code and listens on port 443 for the outbound messages from Salesforce (http requests)--basically LAMP without mysql
4. a router that has a null-routed address and runs ospf to advertise the configured static routes
5. php code that issues commands to the router based on the info provided in the outbound message
