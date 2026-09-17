<?php
/**
 * CoopVote — the project's front door
 *
 * Opening http://localhost/CoopVote/ used to fall through to Apache's
 * directory listing, which is both untidy and a small disclosure: it shows
 * every folder in the project to anyone who asks. Turning listings off left
 * that address with nothing to serve at all, so it answered 403.
 *
 * This is the missing piece: the address people actually type now lands on
 * the sign-in page. Somebody already signed in is sent on to their own
 * dashboard by login.html, so there is no need to decide that here.
 *
 * The redirect is relative, so it keeps working whatever the project folder
 * is called on the computer it was unzipped onto.
 */

declare(strict_types=1);

header('Location: login.html', true, 302);
header('Cache-Control: no-store');

/* A browser that ignores the redirect still gets somewhere to click. */
?>
<!doctype html>
<meta charset="utf-8">
<title>CoopVote</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body {
    margin: 0;
    min-height: 100vh;
    display: grid;
    place-items: center;
    background: #0a1120;
    color: #e8edf5;
    font: 15px/1.6 system-ui, -apple-system, 'Segoe UI', sans-serif;
    text-align: center;
  }
  a { color: #c9a84c; }
</style>
<div>
  <p>Taking you to the CoopVote sign-in page&hellip;</p>
  <p><a href="login.html">Continue to sign in</a></p>
</div>
