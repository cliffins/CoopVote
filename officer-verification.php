<?php
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CoopVote — Officer verification</title><link rel="stylesheet" href="assets/css/officer-verification.css?v=20261112-dense"><script src="assets/js/officer-verification.js" defer></script></head>
<body><main><p class="brand">CoopVote</p><h1 id="heading">Officer email verification</h1><p id="description">Checking your verification link…</p><p id="message" role="status" aria-live="polite"></p>
<form id="verification-form" hidden>
<label for="password" id="password-label">Password</label><div class="password-wrap"><input type="password" id="password" required maxlength="72" autocomplete="new-password"><button type="button" id="toggle-password" aria-label="Show password" aria-pressed="false">Show</button></div>
<div id="confirmation"><label for="confirm-password">Confirm new password</label><input type="password" id="confirm-password" maxlength="72" autocomplete="new-password"></div>
<p id="password-help">Use at least 8 characters and 3 types: uppercase letters, lowercase letters, numbers or symbols.</p><button id="verify-submit" type="submit">Verify email</button></form>
<p><a href="login.php">Go to sign in</a></p><p class="note">Links expire after 30 minutes and work once. If this link is expired or was replaced, ask your cooperative administrator to resend verification.</p></main></body></html>
