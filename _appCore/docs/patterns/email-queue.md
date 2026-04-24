# Pattern — Email Queue

> One-line purpose: enqueue outbound mail at request time, deliver via cron, with retries and dead-lettering.

## When to use this pattern
- Any outbound email beyond a handful per request — never block the HTTP response on SMTP.
- Transactional mail (password reset, notifications) where failure tolerance matters.
- Bulk mail (newsletter, import results) where you want throughput control.

Does NOT fit:
- Strictly synchronous "email-me-the-thing-I-just-downloaded" flows that must complete before redirect — send inline via PHPMailer and handle the error there.
- Marketing automation with sequencing, A/B, suppression lists — use a dedicated provider.

## Schema

```sql
-- Migration: 0024_email_queue.sql
CREATE TABLE email_queue (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    to_email      VARCHAR(255) NOT NULL,
    to_name       VARCHAR(150) NULL,
    subject       VARCHAR(255) NOT NULL,
    body_html     MEDIUMTEXT NOT NULL,
    body_text     MEDIUMTEXT NULL,
    status        ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at       TIMESTAMP NULL,
    error_message VARCHAR(500) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_due (status, next_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- `status = 'sending'` is a claim marker so concurrent dispatchers don't double-send; reset back to `pending` on crash during `dispatch()`.
- `next_attempt` drives exponential backoff — set forward on each failed attempt.
- `error_message` is the last failure reason; earlier failures are logged in `smtp.json`.

## Service skeleton

```php
<?php
declare(strict_types=1);
namespace AppCore\Modules\Communications\Services;

use AppCore\Core\Database;
use AppCore\Core\Logger;
use PHPMailer\PHPMailer\PHPMailer;

final class EmailQueueService
{
    private const MAX_ATTEMPTS = 5;
    private const BACKOFF_SECS = [60, 300, 900, 3600, 21600]; // 1m, 5m, 15m, 1h, 6h

    public function __construct(
        private readonly Database $db,
        /** @var array{host:string,port:int,user:string,password:string,from_email:string,from_name:string} */
        private readonly array $smtp,
    ) {}

    public function enqueue(string $toEmail, string $subject, string $bodyHtml, ?string $toName = null, ?string $bodyText = null): int
    {
        return $this->db->insert('email_queue', [
            'to_email'  => $toEmail,
            'to_name'   => $toName,
            'subject'   => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText ?? strip_tags($bodyHtml),
        ]);
    }

    /** Called from cron handler; returns number of attempted sends. */
    public function dispatch(int $batchSize = 50): int
    {
        $sent = 0;
        $rows = $this->db->fetchAll(
            "SELECT id FROM email_queue
              WHERE status = 'pending' AND next_attempt <= NOW()
              ORDER BY id LIMIT ?",
            [$batchSize]
        );

        foreach ($rows as $r) {
            // Claim row (optimistic lock)
            $claimed = $this->db->query(
                "UPDATE email_queue SET status = 'sending'
                  WHERE id = ? AND status = 'pending'",
                [$r['id']]
            );
            if ($claimed->rowCount() === 0) continue;

            $msg = $this->db->fetchOne(
                'SELECT to_email, to_name, subject, body_html, body_text, attempts FROM email_queue WHERE id = ?',
                [$r['id']]
            );
            try {
                $this->send($msg);
                $this->db->update('email_queue', [
                    'status'   => 'sent',
                    'sent_at'  => date('Y-m-d H:i:s'),
                    'attempts' => ($msg['attempts'] ?? 0) + 1,
                ], ['id' => $r['id']]);
                $sent++;
            } catch (\Throwable $e) {
                $this->recordFailure((int) $r['id'], (int) ($msg['attempts'] ?? 0) + 1, $e->getMessage());
            }
        }
        return $sent;
    }

    private function send(array $msg): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host     = $this->smtp['host'];
        $mail->Port     = $this->smtp['port'];
        $mail->Username = $this->smtp['user'];
        $mail->Password = $this->smtp['password'];
        $mail->SMTPAuth = true;
        $mail->setFrom($this->smtp['from_email'], $this->smtp['from_name']);
        $mail->addAddress($msg['to_email'], $msg['to_name'] ?? '');
        $mail->Subject = $msg['subject'];
        $mail->isHTML(true);
        $mail->Body    = $msg['body_html'];
        $mail->AltBody = $msg['body_text'] ?? strip_tags($msg['body_html']);
        $mail->send();
    }

    private function recordFailure(int $id, int $attempts, string $err): void
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->db->update('email_queue', [
                'status'        => 'failed',
                'attempts'      => $attempts,
                'error_message' => substr($err, 0, 500),
            ], ['id' => $id]);
            Logger::error('Email dead-lettered', ['id' => $id, 'error' => $err]);
            return;
        }
        $wait = self::BACKOFF_SECS[$attempts - 1] ?? 21600;
        $this->db->update('email_queue', [
            'status'        => 'pending',
            'attempts'      => $attempts,
            'next_attempt'  => date('Y-m-d H:i:s', time() + $wait),
            'error_message' => substr($err, 0, 500),
        ], ['id' => $id]);
    }
}
```

## Controller integration

```php
public function sendPasswordReset(Request $request): Response
{
    $email = (string) $request->getParam('email', '');
    $user  = $this->users->findByEmail($email);
    if ($user !== null) {
        $token = $this->auth->createResetToken($user['id']);
        $this->emailQueue->enqueue(
            $user['email'],
            t('auth.reset.subject'),
            $this->render('@auth/emails/reset.html.twig', ['token' => $token])->getBody(),
            $user['name']
        );
    }
    $this->flash('info', t('auth.reset.check_email'));
    return $this->redirect(route('auth.login'));
}
```

Cron handler in `module.php`:

```php
'cron' => [\AppCore\Modules\Communications\Jobs\DispatchEmailQueue::class],
```

Where the job's `run()` calls `$this->service->dispatch()`.

## Template hints

Render the email HTML with a dedicated layout `@communications/emails/_layout.html.twig` (inline CSS, no `<script>`). Keep subject in its own i18n key. For preview in admin, render the same template to the browser without sending.

## Pitfalls

- Not claiming the row before sending — two cron runs overlapping will double-send.
- Growing `email_queue` forever. Add a retention job: delete `status='sent' AND sent_at < NOW() - INTERVAL 30 DAY`.
- Logging full HTML body to `smtp.json` — leaks PII. Log recipient + subject + id, not body.
- Retrying on permanent failures (invalid-address 5xx). Inspect the exception code and mark `failed` immediately for non-retryable classes.
- Calling `dispatch()` synchronously from a request — defeats the point. Always invoke from cron.

## Further reading
- PHPMailer is the only transport appCore assumes. Swap at the `send()` boundary if you need SES/Mailgun API.
- [timeline.md](timeline.md) — record `*.email_sent` / `*.email_failed` on the related entity if there is one.
