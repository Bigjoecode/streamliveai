<?php
/**
 * Renders a list of jobs with players for finished ones. Used by the tool pages
 * (one tool's results) and Job History (everything).
 */

function sleek_render_jobs(array $jobs, bool $showTool): void
{
    // Where Delete returns to: the page showing this list.
    // From the address, not the script name — the same under Apache, LiteSpeed and
    // PHP's dev router. job-delete only accepts known pages anyway.
    $here = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '.php') ?: 'job-history';
    $badge = [
        'completed'  => ['Done',       'border-reel/35 bg-reel/10 text-reel'],
        'processing' => ['Processing', 'border-signal/35 bg-signal/10 text-signal'],
        'failed'     => ['Failed',     'border-tally/35 bg-tally/10 text-tally'],
    ];
    ?>
    <ul class="mt-5 space-y-3">
        <?php foreach ($jobs as $job):
            [$label, $cls] = $badge[$job['status']] ?? [$job['status'], 'border-border text-muted'];
            $async   = !empty(sleek_tool($job['tool'])['async']);
            $waiting = $async && $job['status'] === 'processing';
            if ($waiting) $label = 'Rendering';
            $kind = strtok((string) ($job['output_mime'] ?? ''), '/');   // audio | image | video
            $src  = 'media?job=' . (int) $job['id'];
        ?>
            <li class="rounded-xl border border-border bg-surface px-4 py-4 sm:px-5"<?= $waiting ? ' data-job-poll="' . (int) $job['id'] . '"' : '' ?>>
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[14.5px] font-medium text-paper">
                            <?php if ($showTool): ?><?= e(sleek_tool($job['tool'])['name'] ?? $job['tool']) ?> · <?php endif; ?><?= e($job['title']) ?>
                        </p>
                        <p class="mt-0.5 text-[13px] text-muted">
                            <?= e(sleek_ago((int) $job['created_at'])) ?> ·
                            <?php if ($job['status'] === 'failed'): ?>
                                <?= number_format((int) $job['credits']) ?> credits refunded
                            <?php else: ?>
                                <?= number_format((int) $job['credits']) ?> credits
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="rounded-full border px-2.5 py-0.5 text-[12px] <?= $cls ?>"><?= e($label) ?></span>
                        <?php if ($job['status'] !== 'processing'): ?>
                            <form method="POST" action="job-delete" class="m-0" onsubmit="return confirm('Delete this job and its file? This can't be undone.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="job" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="return" value="<?= e($here) ?>">
                                <button type="submit" aria-label="Delete this job"
                                        class="rounded-full border border-border px-2.5 py-0.5 text-[12px] text-muted transition-colors hover:border-tally/50 hover:text-tally">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($job['status'] === 'completed' && $job['output_file']): ?>
                    <div class="mt-3 flex flex-col gap-2 <?= $kind === 'audio' ? 'sm:flex-row sm:items-center' : '' ?>">
                        <?php if ($kind === 'video'): ?>
                            <video controls preload="metadata" playsinline class="max-h-[420px] w-full rounded-lg bg-ink" src="<?= $src ?>"></video>
                        <?php elseif ($kind === 'image'): ?>
                            <a href="<?= $src ?>" target="_blank" rel="noopener" class="block">
                                <img src="<?= $src ?>" alt="<?= e($job['title']) ?>" loading="lazy" class="max-h-[420px] w-auto max-w-full rounded-lg border border-border">
                            </a>
                        <?php else: ?>
                            <audio controls preload="none" class="h-10 w-full sm:flex-1" src="<?= $src ?>"></audio>
                        <?php endif; ?>
                        <a href="<?= $src ?>&amp;download=1"
                           class="shrink-0 self-start rounded-lg border border-border px-3.5 py-2 text-center text-[13px] text-paper transition-colors hover:border-signal/40">Download</a>
                    </div>
                <?php elseif ($waiting): ?>
                    <p class="mt-2 flex items-center gap-2 text-[13px] text-muted">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-signal" aria-hidden="true"></span>
                        Rendering — usually a few minutes. This updates by itself; you can leave the page.
                    </p>
                <?php elseif ($job['status'] === 'failed' && $job['error']): ?>
                    <p class="mt-2 text-[13px] text-muted"><?= e($job['error']) ?></p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

/** Flash banner, shared by pages that redirect back to themselves. */
function sleek_render_flash(?array $flash): void
{
    if (!$flash) return;
    $style = [
        'success' => 'border-reel/40 bg-reel/10',
        'error'   => 'border-tally/40 bg-tally/10',
        'info'    => 'border-signal/40 bg-signal/10',
    ][$flash['type']] ?? 'border-signal/40 bg-signal/10';
    ?>
    <div role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"
         class="mt-6 rounded-lg border <?= $style ?> px-4 py-3 text-[14.5px] text-paper">
        <?= e($flash['message']) ?>
        <?php if (!empty($flash['buy'])): ?>
            <a href="credits-billing" class="ml-1 font-medium text-signal underline">Buy credits</a>
        <?php endif; ?>
    </div>
    <?php
}
