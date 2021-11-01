<?php
echo "HEY YOU LEFT THE ACCIDENTAL RUN PREVENTION IN...";
exit;

require_once './vendor/autoload.php';

putenv('ENVIRONMENT=production');

if(getenv('ENVIRONMENT') !== 'dev') {
    putenv('SSO_STRIPE_ACCOUNT_ID=');
    putenv('SSO_STRIPE_SECRET_KEY=');
    putenv('STRIPE_SECRET_KEY=');
    putenv('STRIPE_PUBLIC_KEY=');
    putenv('STRIPE_CLIENT_ID=');
    putenv('D2_FEE_PERCENTAGE=5'); // OUR FEE PERCENTAGE
} else {
    putenv('SSO_STRIPE_ACCOUNT_ID=');
    putenv('SSO_STRIPE_SECRET_KEY=');
    putenv('STRIPE_SECRET_KEY=');
    putenv('STRIPE_PUBLIC_KEY=');
    putenv('STRIPE_CLIENT_ID=');
    putenv('D2_FEE_PERCENTAGE=5'); // OUR FEE PERCENTAGE
}

function printStripeEnvs()
{
    echo "SSO_STRIPE_SECRET_KEY:".getenv('SSO_STRIPE_SECRET_KEY')." \n";
    echo "STRIPE_SECRET_KEY:".getenv('STRIPE_SECRET_KEY')." \n";
    echo "STRIPE_PUBLIC_KEY:".getenv('STRIPE_PUBLIC_KEY')." \n";
    echo "STRIPE_CLIENT_ID:".getenv('STRIPE_CLIENT_ID')." \n";
    echo "D2_FEE_PERCENTAGE:".getenv('D2_FEE_PERCENTAGE')." \n";
}

function wasInPlayerSubscription(\Stripe\Subscription $subscription): bool
{
    return !empty($subscription->metadata->toArray()['inplayer'] ?? null);
}

function isD2ClonedSubscription(\Stripe\Subscription $subscription): bool
{
    return !empty($subscription->metadata->toArray()['subscription_converted'] ?? null);
}

function findSubscriptionItem(\Stripe\Subscription $inPlayerSubscription)
{
    $subscriptionItemIds = [];
    /** @var \Stripe\SubscriptionItem $subscriptionItem */
    foreach ($inPlayerSubscription->items->autoPagingIterator() as $subscriptionItem){
        $subscriptionItemIds[]['price'] = $subscriptionItem->price->id;
    }
    return $subscriptionItemIds;
}

function convertToUTC($unixTimestamp)
{
    return \Carbon\Carbon::createFromTimestamp($unixTimestamp)->utc()->format('Y-m-d H:i:s');
}

function debug($item)
{
    echo print_r($item, true) . "\n";
}


function copySubscription(\Stripe\StripeClient $d2Client, \Stripe\Subscription $inPlayerSubscription, float $applicationFeePercent): \Stripe\Subscription
{
    $metaData = $inPlayerSubscription->metadata->toArray();
    $metaData['subscription_converted'] = 1; // Add a flag to know that we converted this subscription
    return $d2Client->subscriptions->create([
        'customer' => $inPlayerSubscription->customer,
        'items' => findSubscriptionItem($inPlayerSubscription),
        'metadata' => $metaData,
        'application_fee_percent' => $applicationFeePercent,
        'trial_end' => $inPlayerSubscription->current_period_end,
    ], ['stripe_account' => getenv('SSO_STRIPE_ACCOUNT_ID')]);
}

function cancelSubscription(\Stripe\StripeClient $ssoClient, \Stripe\Subscription $inPlayerSubscription)
{
    $ssoClient->subscriptions->cancel($inPlayerSubscription->id);
}

function isActive(\Stripe\Subscription $stripeSubscription): bool
{
    return $stripeSubscription->status === 'active';
}

function isTrial(\Stripe\Subscription $stripeSubscription): bool
{
    return $stripeSubscription->status === 'trialing';
}

function isCanceled(\Stripe\Subscription $stripeSubscription): bool
{
    return $stripeSubscription->status === 'canceled';
}

function isPastDue(\Stripe\Subscription $stripeSubscription): bool
{
    return $stripeSubscription->status === 'past_due';
}

//printStripeEnvs();

// We need a client instantiated with with SSO's secret
$ssoStripeClient = new \Stripe\StripeClient(getenv('SSO_STRIPE_SECRET_KEY'));
$d2StripeClient = new \Stripe\StripeClient(getenv('STRIPE_SECRET_KEY'));

$stripeSubscriptions = $ssoStripeClient->subscriptions->all();
$file = fopen('stripe-conversion-'.date('YmdHis').'.csv', "w");
$errorFile = fopen('errors-stripe-conversion-'.date('YmdHis').'.csv', "w");
$exceptionsFile = fopen('exceptions-stripe-conversion-'.date('YmdHis').'.csv', "w");
fputcsv($file, [
    'InPlayer Stripe Subscription ID',
    'Donate2 Stripe Subscription ID',
    'Stripe Customer Email',
]);
fputcsv($errorFile, [
    'InPlayer Stripe Subscription ID',
    'Donate2 Stripe Subscription ID',
    'Stripe Customer Email',
    'Exception',
]);
$subscriptions = [];
/** @var \Stripe\Subscription $stripeSubscription */
foreach ($stripeSubscriptions->autoPagingIterator() as $stripeSubscription) {
    $subscriptions[] = $stripeSubscription;
}
foreach ($subscriptions as $stripeSubscription) {
    try {
        debug($stripeSubscription->id);
        debug($stripeSubscription->status);
        if(wasInPlayerSubscription($stripeSubscription) && !isD2ClonedSubscription($stripeSubscription) && !isCanceled($stripeSubscription)) {
            if(!isPastDue($stripeSubscription)) {
                $donate2Subscription = copySubscription($d2StripeClient, $stripeSubscription, floatval(getenv('D2_FEE_PERCENTAGE')));
                debug("Subscription Copied!");
                $customer = $ssoStripeClient->customers->retrieve($stripeSubscription->customer);
                debug("Customer retrieved!");
                // Cancel the InPlayer subscription
                cancelSubscription($ssoStripeClient, $stripeSubscription);
                debug("Subscription cancelled!");
                fputcsv($file, [
                    $stripeSubscription->id ?? '',
                    $donate2Subscription->id ?? '',
                    $customer->email ?? '',
                ]);
            } else {
                debug("Past Due Subscription cancelled!");
                // Cancel the InPlayer subscription
                cancelSubscription($ssoStripeClient, $stripeSubscription);
            }
        }
    } catch (Exception $e) {
        fputcsv($errorFile, [
            $stripeSubscription->id ?? '',
            $donate2Subscription->id ?? '',
            $stripeSubscription->customer->email ?? '',
            'Message: ' . ($e->getMessage() ?? '') . 'Stack Trace: ' . str_replace("\n", '', json_encode($e->getTraceAsString())),
        ]);
        $errorMessage = $e . "\n";
        echo $errorMessage;
        fwrite($exceptionsFile, $errorMessage . $e->getTraceAsString() . "\n\n");
        die();
    }
}

fclose($file);
fclose($errorFile);
fclose($exceptionsFile);
