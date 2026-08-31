<?php      
interface Notifier
  {
      public function send(string $recipient, string $message): void;
  }

class TwilioNotifier implements Notifier
  {
      public function send(string $recipient, string $message): void
      {
          (new TwilioClient($_ENV['TWILIO_SID']))->sendSms($recipient, $message);
      }
  }

class SpeedAlertRule
  {
      public function __construct(private Notifier $notifier) {}

      public function check(Reading $reading): void
      {
          if ($reading->getSpeed() > 70) {
              $this->notifier->send(
                  $reading->getVehicle()->getFleetManagerPhone(),
                  'Speeding alert'
              );
          }
      }
  }