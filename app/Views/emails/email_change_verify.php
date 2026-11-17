<p>Hello <?= esc($memberName) ?>,</p>
<p>Confirm your new email address for <?= esc($appName) ?> by opening this link within one hour:</p>
<p><a href="<?= esc($verifyUrl) ?>"><?= esc($verifyUrl) ?></a></p>
<p>If you did not request this change, ignore this message. Your current login email stays active until you confirm.</p>
