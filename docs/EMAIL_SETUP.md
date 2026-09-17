# Setting up Gmail for CoopVote

CoopVote sends email through Gmail's SMTP server using PHPMailer, which is
already installed. All messages are written in **English only**.

Email is **switched off** until you finish these steps, so nothing is sent
by accident while you are still configuring it.

---

## What gets sent, and when

| When | Who receives it | Subject |
|---|---|---|
| Somebody registers | the applicant | We received your CoopVote registration |
| An administrator approves them | the applicant | Your CoopVote membership has been approved |
| An administrator rejects them | the applicant | About your CoopVote registration |
| A member casts a ballot | the voter | Your CoopVote voting receipt — CV-XXXXXXXX |

The voting receipt contains the receipt code only. It never says who the
member voted for, because the system itself cannot work that out.

---

## Step 1 — Turn on 2-Step Verification

Google will not issue an App Password without it.

1. Go to <https://myaccount.google.com/security>
2. Under **How you sign in to Google**, open **2-Step Verification**
3. Follow the prompts (usually your phone number)

If you already have it on, skip to Step 2.

---

## Step 2 — Create an App Password

**Your normal Gmail password will not work here.** Google blocks it for apps
like this one. You need a 16-character App Password.

1. Go to <https://myaccount.google.com/apppasswords>
   (or search "App passwords" in your Google Account)
2. Sign in again if asked
3. Type a name you will recognise later, e.g. `CoopVote`
4. Press **Create**
5. Google shows a 16-character password like `abcd efgh ijkl mnop`

Copy it now — Google will not show it a second time.

> **Remove the spaces.** `abcd efgh ijkl mnop` must be entered as
> `abcdefghijklmnop`.

*Can't find the App Passwords page?* It only appears when 2-Step
Verification is on. Work or school accounts may have it disabled by an
administrator — ask them, or use a personal Gmail account.

---

## Step 3 — Put the details in the config

Open **`config/config.php`** and fill in these lines:

```php
const MAIL_ENABLED  = false;          // leave false for now — Step 4 turns it on

const MAIL_HOST     = 'smtp.gmail.com';
const MAIL_PORT     = 587;            // keep 587
const MAIL_SECURE   = 'tls';          // keep tls
const MAIL_USERNAME = 'yourname@gmail.com';    // your full Gmail address
const MAIL_PASSWORD = 'abcdefghijklmnop';      // the App Password, no spaces

const MAIL_FROM_ADDRESS = 'yourname@gmail.com';
const MAIL_FROM_NAME    = 'CoopVote';

const MAIL_REPLY_TO = '';             // optional: an address members may reply to
```

Notes:

* `MAIL_FROM_ADDRESS` should match `MAIL_USERNAME`. Gmail rewrites the
  sender to your own address anyway, so a different one is only confusing.
* Port `587` with `tls` is the normal choice. If your network blocks it,
  use `465` with `'ssl'` instead.

---

## Step 4 — Test before switching it on

Run this from the project folder, using an address you can actually check:

```
C:\xampp\php\php.exe tools\send_test_email.php you@example.com
```

It prints the whole SMTP conversation, so you can see exactly where it
stops if something is wrong.

**On success** it ends with:

```
SUCCESS — the message was accepted by Gmail.
```

Check that inbox — including **Spam**, the first message often lands there.

Now, and only now, set:

```php
const MAIL_ENABLED = true;
```

---

## If the test fails

**`SMTP Error: Could not authenticate` / "Username and Password not accepted"**
You used your normal Gmail password. It must be the 16-character App
Password from Step 2, with the spaces removed.

**`SMTP connect() failed` / "Could not connect to SMTP host"**
* Your network or antivirus is blocking port 587. Try `MAIL_PORT = 465`
  with `MAIL_SECURE = 'ssl'`.
* `php_openssl` may be off. In `C:\xampp\php\php.ini` find
  `;extension=openssl`, remove the leading `;`, and restart Apache.
  Check with: `C:\xampp\php\php.exe -r "var_dump(extension_loaded('openssl'));"`

**Nothing arrives, but the test says SUCCESS**
Look in Spam. Gmail often files the first automated message there. Marking
it "Not spam" once usually settles it.

**App Passwords page will not open**
2-Step Verification is off (Step 1), or an administrator has disabled the
feature on a work/school account.

---

## Where to look afterwards

Every attempt is recorded in **`logs/mail.log`**:

```
2026-08-08 16:50:17  SENT -> juan@example.com | Your CoopVote voting receipt — CV-7F3K2QX9
2026-08-08 16:51:02  FAILED -> maria@example.com | ... | SMTP Error: ...
2026-08-08 16:52:10  SKIPPED (MAIL_ENABLED is false) -> ...
```

Set `MAIL_DEBUG_LOG = false` in the config to stop writing it.

---

## Things worth knowing

**A failed email never breaks the app.** If Gmail is unreachable, a vote is
still recorded, a registration is still created, an approval still stands.
The failure is logged and the member simply does not get that message. This
is deliberate: a mail server being down must not stop an election.

**Gmail has a daily sending limit** — roughly 500 messages a day for a free
account. That is ample for a cooperative of this size, but if you grow past
it, look at a dedicated sending service.

**The App Password sits in plain text in `config/config.php`.** Anyone who
can read that file can send mail as you. Do not commit it to a public
repository, and revoke the password at
<https://myaccount.google.com/apppasswords> if it ever leaks — revoking
affects only this app.
