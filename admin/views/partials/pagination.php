<?php
/**
 * Pagination control. Preserves every current query parameter.
 *
 * @var \App\Core\Paginator $paginator
 * @var string              $label     Noun for the record count, e.g. "leads"
 */

use App\Core\Url;

$label = $label ?? 'records';

if ($paginator->total === 0) {
    return;
}
?>
<div class="lrms-pager">
    <div class="info">
        Showing <?= e((string) $paginator->from()) ?>–<?= e((string) $paginator->to()) ?>
        of <?= e(number_format($paginator->total)) ?> <?= e($label) ?>
    </div>

    <?php if ($paginator->lastPage() > 1): ?>
        <nav aria-label="Pagination">
            <ul class="pagination pagination-sm">
                <li class="page-item<?= $paginator->hasPrevious() ? '' : ' disabled' ?>">
                    <a class="page-link" href="<?= e(Url::withQuery(['page' => $paginator->page - 1])) ?>"
                       aria-label="Previous page">&laquo;</a>
                </li>

                <?php foreach ($paginator->window() as $entry): ?>
                    <?php if ($entry === '...'): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php else: ?>
                        <li class="page-item<?= (int) $entry === $paginator->page ? ' active' : '' ?>">
                            <a class="page-link" href="<?= e(Url::withQuery(['page' => (int) $entry])) ?>">
                                <?= e((string) $entry) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>

                <li class="page-item<?= $paginator->hasNext() ? '' : ' disabled' ?>">
                    <a class="page-link" href="<?= e(Url::withQuery(['page' => $paginator->page + 1])) ?>"
                       aria-label="Next page">&raquo;</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
