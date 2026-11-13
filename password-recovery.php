<?php
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CoopVote — Password recovery</title><link rel="stylesheet" href="assets/css/officer-verification.css?v=20261112-dense"><link rel="stylesheet" href="assets/css/password-recovery.css?v=20261113"><script src="assets/js/password-recovery.js?v=20261113-links2" defer></script></head>
<body><main><p class="brand">CoopVote</p><h1 id="heading">Forgot password?</h1><p id="description">Enter the verified email address on your account to request a password reset link.</p><p id="message" role="status" aria-live="polite"></p>
<form id="request-form"><label for="email">Email address</label><input type="email" id="email" required maxlength="254" autocomplete="email"><button type="submit" id="request-submit">Send reset link</button></form>
<form id="reset-form" hidden>
<label for="password">New password</label><div class="password-wrap"><input type="password" id="password" required minlength="8" maxlength="72" autocomplete="new-password"><button type="button" id="toggle-password" aria-label="Show passwords" aria-pressed="false">Show</button></div>
<label for="confirm-password">Confirm new password</label><input type="password" id="confirm-password" required minlength="8" maxlength="72" autocomplete="new-password">
<p id="password-help">Use at least 8 characters and 3 types: uppercase letters, lowercase letters, numbers or symbols.</p><button id="reset-submit" type="submit">Reset password</button></form>
<p><a href="login.php">Back to sign in</a> · <a href="password-recovery.php" id="new-link" hidden>Request a new link</a></p><p class="note">Reset links expire after 30 minutes and work once. If you no longer have access to your email, contact the cooperative office.</p></main></body></html>
