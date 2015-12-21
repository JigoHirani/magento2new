<?php
namespace Riskified\Decider\Observer;

use Magento\Framework\Event\ObserverInterface;

class UpdateOrderState implements ObserverInterface
{
    private $logger;
    private $apiOrderLayer;
    private $apiConfig;
    private $apiOrderConfig;

    public function __construct(
        \Riskified\Decider\Api\Log $logger,
        \Riskified\Decider\Api\Config $config,
        \Riskified\Decider\Api\Order\Config $apiOrderConfig,
        \Riskified\Decider\Api\Order $orderApi
    )
    {
        $this->logger           = $logger;
        $this->apiOrderConfig   = $apiOrderConfig;
        $this->apiOrderLayer    = $orderApi;
        $this->apiConfig        = $config;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getOrder();
        $riskifiedStatus = (string)$observer->getStatus();
        $riskifiedOldStatus = (string)$observer->getOldStatus();
        $description = (string)$observer->getDescription();
        $newState = $newStatus = null;
        $currentState = $order->getState();
        $currentStatus = $order->getStatus();

        $this->logger->log("Checking if should update order '" . $order->getId() . "' from state: '$currentState' and status: '$currentStatus'");
        $this->logger->log("Data received from riskified: status: " . $riskifiedStatus . ", old_status: " . $riskifiedOldStatus . ", description: " . $description);
        $this->logger->log("On Hold Status Code : ".$this->apiOrderConfig->getOnHoldStatusCode()." and Transport Error Status Code : " . $this->apiOrderConfig->getTransportErrorStatusCode());

        switch ($riskifiedStatus) {
            case 'approved':
                if ($currentState == \Magento\Sales\Model\Order::STATE_HOLDED
                    && ($currentStatus == $this->apiOrderConfig->getOnHoldStatusCode()
                        || $currentStatus == $this->apiOrderConfig->getTransportErrorStatusCode())
                ) {
                    $newState = $this->apiOrderConfig->getSelectedApprovedState();
                    $newStatus = $this->apiOrderConfig->getSelectedApprovedStatus();
                }
                break;
            case 'declined':
                if ($currentState == \Magento\Sales\Model\Order::STATE_HOLDED
                    && ($currentStatus == $this->apiOrderConfig->getOnHoldStatusCode()
                        || $currentStatus == $this->apiOrderConfig->getTransportErrorStatusCode())
                ) {
                    $newState = $this->apiOrderConfig->getSelectedDeclinedState();
                    $newStatus = $this->apiOrderConfig->getSelectedDeclinedStatus();
                }
                break;
            case 'submitted':
                if ($currentState == \Magento\Sales\Model\Order::STATE_PROCESSING
                    || ($currentState == \Magento\Sales\Model\Order::STATE_HOLDED
                        && $currentStatus == $this->apiOrderConfig->getTransportErrorStatusCode())
                ) {
                    $newState = \Magento\Sales\Model\Order::STATE_HOLDED;
                    $newStatus = $this->apiOrderConfig->getOnHoldStatusCode();
                }
                break;
            case 'error':
                if ($currentState == \Magento\Sales\Model\Order::STATE_PROCESSING
                    && $this->apiConfig->isAutoInvoiceEnabled()
                ) {
                    $newState = \Magento\Sales\Model\Order::STATE_HOLDED;
                    $newStatus = $this->apiOrderConfig->getTransportErrorStatusCode();
                }
        }
        
        $this->logger->log("Old State : " . $currentState);
        $this->logger->log("Old Status : " . $currentStatus);
        $this->logger->log("New State : " . $newState);
        $this->logger->log("New Status : " . $newStatus);
        
        $changed = false;
        if ($newState
            && ($newState != $currentState || $newStatus != $currentStatus)
            && $this->apiConfig->getConfigStatusControlActive()
        ) {
            if ($newState == \Magento\Sales\Model\Order::STATE_CANCELED) {
                $this->logger->log("Order '" . $order->getId() . "' should be canceled - calling cancel method");
                $order->cancel();
            }
            $order->setState($newState, $newStatus, $description);
            $order->setStatus($newStatus);

			$order->addStatusHistoryComment($description);
            $this->logger->log("Updated order '" . $order->getId() . "' to: state:  '$newState', status: '$newStatus', description: '$description'");
            $changed = true;
        } elseif ($description && $riskifiedStatus != $riskifiedOldStatus) {
            $this->logger->log("Updated order " . $order->getId() . " history comment to: " . $description);
            $order->addStatusHistoryComment($description);
            $changed = true;
        } else {
            $this->logger->log("No update to state,status,comments is required for " . $order->getId());
        }

        if ($changed) {
            try {
                $order->save();
            } catch (\Exception $e) {
                $this->logger->log("Error saving order: " . $e->getMessage());
                return;
            }
        }
    }
}
