<?php

namespace App\Services;

use App\Data\StockMinimumReachedEvent;
use InvalidArgumentException;
use PhpAmqpLib\Message\AMQPMessage;

class StockMinimumEventProcessor
{
    public function __construct(private StockMinimumNotificationRecorder $notifications) {}

    public function process(AMQPMessage $message): bool
    {
        $event = StockMinimumReachedEvent::fromJson($message->getBody());
        $messageId = $message->get_properties()['message_id'] ?? null;

        if (! is_string($messageId) || strtolower($messageId) !== $event->eventId) {
            throw new InvalidArgumentException('The AMQP message identifier does not match eventId.');
        }

        $isNewNotification = $this->notifications->record($event);

        $message->ack();

        return $isNewNotification;
    }
}
