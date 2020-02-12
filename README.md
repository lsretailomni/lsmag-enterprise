# LS Ecommerce - Magento Integration (Under Development)

## Compatibility
1. Magento Commerce/Enterprise 2.3.3 or later
2. LS Central 14.02
3. LS Omni Server 4.5

## Installation:

1. Navigate to your magento2 installation directory and run `composer require "lsretail/lsmag-enterprise"`
2. Run `composer update` to install all the dependencies it needs.
3. To enable all our modules, run command from command line, `php bin/magento module:enable Ls_Commerce`
followed by `php bin/magento setup:upgrade ` and  `php bin/magento setup:di:compile` from Magento 2 instance so that it can update the magento2 database with our modules schema and interceptor files.
4. Once done, you will see the list of our modules by running `php bin/magento module:status` which means our module is now good to go.  
5. To test the connectivity to Omni server, run `php bin/magento omni:client:ping` to test the connection. If Ping return successfully, then you can procedd with next steps.
6. Once done, you will see all the new tables created in your Magento 2 database with prefix `ls_replication_*`
7. Next thing is to set configurations of Nav store and Hierarchy from backend to replicate data, to do so, navigate to Stores->Configuration->LS Retail->General Configuration, and choose the store and Hierarchy code to replicate data. Make sure you do all the configurations which are required on the Omni server for ecommerce i-e disabling security token for authentication.
8. If your server is setup for cron, then you will see all the new crons created in the `cron_schedule` table if not, it means your server is not setup to schedule cron, to trigger the cron manually,run `php bin/magento cron:run from command line. 
9. To Trigger the cron manually from admin panel, navigate to LS Retail -> Cron Listing from the left menu and click on the cron which needs to be run.
10. To see if the data is replicated in the Magento completely or not, you can navigate to any Replication job from `LS Retail -> Replication` Status and there we can see the status with `Processed` or `Not Processed` in the grid.

## Features by default Disabled
1. Store Credit
2. Rewards Points
3. Gift Message
4. Gift Wrapping
5. Return Merchandise Authorization (RMA)
