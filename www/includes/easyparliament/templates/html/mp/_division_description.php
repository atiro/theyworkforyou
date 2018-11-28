<li id="<?= $division['division_id'] ?>" class="<?= $show_all || $division['strong'] ? 'policy-vote--major' : 'policy-vote--minor' ?>">
    <span class="policy-vote__date">On <?= strftime('%e %b %Y', strtotime($division['date'])) ?>:</span>
    <span class="policy-vote__text"><?= $full_name ?><?= $division['text'] ?></span>
    <a class="vote-description__source" href="/divisions/<?= $division['division_id'] ?>/mp/<?= $person_id ?>">Show vote</a>
</li>

