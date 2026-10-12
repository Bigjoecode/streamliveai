<?php
/**
 * SleekAI — the credit ledger. Every change to a balance goes through here.
 *
 * users.credits is the running balance; credit_ledger is the history that explains
 * it. Both change in the same transaction, so they can't drift apart. Each entry
 * carries a (kind, reference) pair that is UNIQUE — the same payment, bonus or job
 * can never be applied twice, however many times the code path runs.
 */

declare(strict_types=1);

/** Thrown when the same (kind, reference) has already been applied. */
final class SleekAlreadyApplied extends RuntimeException {}

/** Thrown when a debit would take the balance below zero. */
final class SleekInsufficientCredits extends RuntimeException {}

/**
 * Add credits. Returns the new balance.
 *
 * Joins the caller's transaction if one is open, so a payment's status change and
 * its credit commit or roll back together.
 */
function sleek_credit(int $userId, int $amount, string $kind, string $reference, string $description): int
{
    if ($amount <= 0) throw new InvalidArgumentException('Credit amount must be positive.');
    return sleek_ledger_apply($userId, $amount, $kind, $reference, $description);
}

/** Spend credits. Returns the new balance, or throws SleekInsufficientCredits. */
function sleek_debit(int $userId, int $amount, string $kind, string $reference, string $description): int
{
    if ($amount <= 0) throw new InvalidArgumentException('Debit amount must be positive.');
    return sleek_ledger_apply($userId, -$amount, $kind, $reference, $description);
}

function sleek_ledger_apply(int $userId, int $delta, string $kind, string $reference, string $description): int
{
    $db  = sleek_db();
    $own = !$db->inTransaction();

    // Inside someone else's transaction, fence this entry with a savepoint: if the
    // ledger insert turns out to be a duplicate, the balance change above it must
    // be undone too, without throwing away the caller's work.
    $own ? $db->beginTransaction() : $db->exec('SAVEPOINT sleek_ledger');

    try {
        // One atomic statement both checks and moves the balance, so two debits
        // racing each other can't both pass a "have enough?" check made earlier.
        $update = $delta >= 0
            ? $db->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
            : $db->prepare('UPDATE users SET credits = credits + ? WHERE id = ? AND credits >= ?');
        $update->execute($delta >= 0 ? [$delta, $userId] : [$delta, $userId, -$delta]);

        if ($update->rowCount() !== 1) {
            throw $delta >= 0
                ? new RuntimeException("No user #{$userId} to credit.")
                : new SleekInsufficientCredits('Not enough credits.');
        }

        $stmt = $db->prepare('SELECT credits FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $balance = (int) $stmt->fetchColumn();

        try {
            $db->prepare('INSERT INTO credit_ledger (user_id, delta, balance_after, kind, reference, description, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?)')
               ->execute([$userId, $delta, $balance, $kind, $reference, $description, time()]);
        } catch (PDOException $e) {
            if (sleek_is_duplicate($e)) throw new SleekAlreadyApplied("{$kind}:{$reference} already applied.");
            throw $e;
        }

        $own ? $db->commit() : $db->exec('RELEASE SAVEPOINT sleek_ledger');
        return $balance;
    } catch (Throwable $e) {
        if ($own) {
            if ($db->inTransaction()) $db->rollBack();
        } else {
            $db->exec('ROLLBACK TO SAVEPOINT sleek_ledger');
            $db->exec('RELEASE SAVEPOINT sleek_ledger');
        }
        throw $e;
    }
}

/**
 * Totals for one user, for the dashboard.
 *
 * @return array{used: int, referral_earned: int}
 */
function sleek_credit_totals(int $userId): array
{
    $stmt = sleek_db()->prepare(
        "SELECT
            COALESCE(-SUM(CASE WHEN kind IN ('job_spend', 'job_refund') THEN delta END), 0) AS used,
            COALESCE(SUM(CASE WHEN kind = 'referral_bonus' THEN delta END), 0)              AS referral_earned
         FROM credit_ledger WHERE user_id = ?"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return ['used' => (int) $row['used'], 'referral_earned' => (int) $row['referral_earned']];
}
