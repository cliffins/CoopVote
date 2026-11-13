<?php require __DIR__ . '/../includes/admin-page.php'; ?>
<!DOCTYPE html>
<html lang="en" data-cv-theme="<?= device_theme() ?>">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content" />
  <title>CoopVote — Candidate Management</title>
  <link
    href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap"
    rel="stylesheet" />
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0
    }

    :root {
      --navy: #0b1931;
      --navy2: #0f2040;
      --navy3: #162a52;
      --navy4: #1e3566;
      --gold: #c9a84c;
      --gold2: #e8c96a;
      --white: #ffffff;
      --muted: #7a8ba0;
      --muted2: #a8b8cc;
      --border: rgba(255, 255, 255, .08);
      --danger: #e05c5c;
      --success: #4caf82;
      --warn: #e0a030;
      --info: #4a9fd4;
      --sidebar-w: 260px;
      --header-h: 64px;
      --radius: 14px;
    }

    html,
    body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      background: var(--navy);
      color: var(--white);
      overflow: hidden
    }

    ::-webkit-scrollbar {
      width: 5px;
      height: 5px
    }

    ::-webkit-scrollbar-track {
      background: transparent
    }

    ::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, .12);
      border-radius: 99px
    }

    .app {
      display: flex;
      height: 100vh;
      overflow: hidden
    }

    @keyframes fadeUp {
      from {
        opacity: 0;
        transform: translateY(18px)
      }

      to {
        opacity: 1;
        transform: translateY(0)
      }
    }

    @keyframes shimmer {

      0%,
      100% {
        left: -70%
      }

      50% {
        left: 140%
      }
    }

    @keyframes pulse {

      0%,
      100% {
        box-shadow: 0 0 0 0 rgba(224, 92, 92, .4)
      }

      50% {
        box-shadow: 0 0 0 5px rgba(224, 92, 92, 0)
      }
    }

    /* ── SIDEBAR ── */
    .sidebar {
      width: var(--sidebar-w);
      flex-shrink: 0;
      background: var(--navy2);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      overflow-y: auto;
      overflow-x: hidden;
      transition: transform .3s cubic-bezier(.22, 1, .36, 1);
      z-index: 200
    }

    .sidebar-logo {
      padding: 28px 22px 22px;
      display: flex;
      align-items: center;
      gap: 12px;
      border-bottom: 1px solid var(--border);
      flex-shrink: 0
    }

    .logo-mark {
      width: 40px;
      height: 40px;
      border-radius: 11px;
      flex-shrink: 0;
      background: linear-gradient(135deg, var(--gold), var(--gold2));
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 16px rgba(201, 168, 76, .3);
      position: relative;
      overflow: hidden
    }

    .logo-mark::after {
      content: '';
      position: absolute;
      top: 0;
      left: -70%;
      width: 40%;
      height: 100%;
      background: rgba(255, 255, 255, .3);
      transform: skewX(-20deg);
      animation: shimmer 4s ease-in-out infinite
    }

    .logo-mark svg {
      width: 20px;
      height: 20px;
      fill: var(--navy)
    }

    .logo-text {
      font-family: 'Playfair Display', serif;
      font-size: 1.25rem;
      font-weight: 800
    }

    .logo-text span {
      color: var(--gold)
    }

    .sidebar-section {
      padding: 20px 14px 8px;
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: 1.8px;
      text-transform: uppercase;
      color: var(--muted);
      flex-shrink: 0
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 11px;
      padding: 11px 14px;
      margin: 2px 8px;
      border-radius: 10px;
      cursor: pointer;
      font-size: .875rem;
      font-weight: 500;
      color: var(--muted2);
      transition: all .2s;
      position: relative;
      white-space: nowrap;
      overflow: hidden;
      text-decoration: none
    }

    .nav-item:hover {
      background: rgba(255, 255, 255, .06);
      color: var(--white)
    }

    .nav-item.active {
      background: rgba(201, 168, 76, .14);
      color: var(--gold);
      font-weight: 600
    }

    .nav-item.active::before {
      content: '';
      position: absolute;
      left: 0;
      top: 20%;
      bottom: 20%;
      width: 3px;
      border-radius: 0 3px 3px 0;
      background: var(--gold)
    }

    .nav-item svg {
      width: 17px;
      height: 17px;
      flex-shrink: 0;
      opacity: .7
    }

    .nav-item.active svg {
      opacity: 1
    }

    .sidebar-footer {
      margin-top: auto;
      padding: 16px 14px;
      border-top: 1px solid var(--border);
      flex-shrink: 0
    }

    .admin-card {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border-radius: 10px;
      background: rgba(255, 255, 255, .05);
      cursor: pointer;
      transition: background .2s
    }

    .admin-card:hover {
      background: rgba(255, 255, 255, .09)
    }

    .admin-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--navy4), var(--navy3));
      border: 2px solid var(--gold);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .75rem;
      font-weight: 700;
      color: var(--gold);
      flex-shrink: 0
    }

    .admin-info {
      flex: 1;
      overflow: hidden
    }

    .admin-name {
      font-size: .82rem;
      font-weight: 600
    }

    .admin-role {
      font-size: .7rem;
      color: var(--muted);
      margin-top: 1px
    }

    /* ── MAIN ── */
    .main {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      min-width: 0
    }

    .header {
      height: var(--header-h);
      flex-shrink: 0;
      background: rgba(11, 25, 49, .85);
      backdrop-filter: blur(20px);
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      padding: 0 28px;
      gap: 16px;
      z-index: 100
    }

    .hamburger {
      display: none;
      background: none;
      border: none;
      cursor: pointer;
      color: var(--muted2);
      padding: 6px;
      border-radius: 8px;
      transition: color .2s, background .2s
    }

    .hamburger:hover {
      color: var(--white);
      background: rgba(255, 255, 255, .07)
    }

    .header-title {
      flex: 1
    }

    .header-title h1 {
      font-family: 'Playfair Display', serif;
      font-size: 1.15rem;
      font-weight: 700
    }

    .header-title p {
      font-size: .75rem;
      color: var(--muted);
      margin-top: 1px
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 10px
    }

    .hbtn {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      border-radius: 10px;
      border: none;
      background: rgba(255, 255, 255, .06);
      color: var(--muted2);
      cursor: pointer;
      transition: all .2s;
      position: relative
    }

    .hbtn:hover {
      background: rgba(255, 255, 255, .12);
      color: var(--white)
    }

    .hbtn svg {
      width: 18px;
      height: 18px
    }

    /* ── CONTENT ── */
    .content {
      flex: 1;
      min-height: 0;
      overflow-y: auto;
      padding: 28px;
      display: flex;
      flex-direction: column;
      gap: 20px
    }

    /* ── SUMMARY ── */
    .summary-strip {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
      animation: none
    }

    .s-card {
      background: var(--navy2);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 18px 20px;
      display: flex;
      align-items: center;
      gap: 14px;
      transition: transform .2s, box-shadow .2s;
      cursor: default
    }

    .s-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 30px rgba(0, 0, 0, .3)
    }

    .s-icon {
      width: 44px;
      height: 44px;
      border-radius: 11px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0
    }

    .s-icon.gold {
      background: rgba(201, 168, 76, .15)
    }

    .s-icon.gold svg {
      color: var(--gold)
    }

    .s-icon.blue {
      background: rgba(74, 159, 212, .15)
    }

    .s-icon.blue svg {
      color: var(--info)
    }

    .s-icon.green {
      background: rgba(76, 175, 130, .15)
    }

    .s-icon.green svg {
      color: var(--success)
    }

    .s-icon.purple {
      background: rgba(155, 127, 212, .15)
    }

    .s-icon.purple svg {
      color: #9b7fd4
    }

    .s-icon svg {
      width: 20px;
      height: 20px
    }

    .s-val {
      font-family: 'Playfair Display', serif;
      font-size: 1.8rem;
      font-weight: 700;
      line-height: 1;
      color: var(--white)
    }

    .s-val.empty {
      color: var(--muted);
      font-size: 1.4rem
    }

    .s-label {
      font-size: .72rem;
      color: var(--muted);
      margin-top: 3px
    }

    /* ── TOOLBAR ── */
    .toolbar {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      animation: none
    }

    .search-wrap {
      flex: 1;
      min-width: 180px;
      position: relative
    }

    .search-wrap svg {
      position: absolute;
      left: 13px;
      top: 50%;
      transform: translateY(-50%);
      width: 16px;
      height: 16px;
      color: var(--muted);
      pointer-events: none
    }

    .search-wrap input {
      width: 100%;
      padding: 10px 14px 10px 38px;
      background: var(--navy2);
      border: 1px solid var(--border);
      border-radius: 10px;
      color: var(--white);
      font-family: 'DM Sans', sans-serif;
      font-size: .875rem;
      outline: none;
      transition: border-color .2s, box-shadow .2s
    }

    .search-wrap input::placeholder {
      color: var(--muted)
    }

    .search-wrap input:focus {
      border-color: var(--gold);
      box-shadow: 0 0 0 3px rgba(201, 168, 76, .12)
    }

    .filter-select {
      padding: 10px 14px;
      background: var(--navy2);
      border: 1px solid var(--border);
      border-radius: 10px;
      color: var(--muted2);
      font-family: 'DM Sans', sans-serif;
      font-size: .875rem;
      outline: none;
      cursor: pointer;
      transition: border-color .2s
    }

    .filter-select:focus {
      border-color: var(--gold)
    }
    #election-filter { width: 240px; max-width: 100%; min-width: 0; text-overflow: ellipsis; }

    .view-toggle {
      display: flex;
      background: var(--navy2);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden
    }

    .view-btn {
      width: 38px;
      height: 38px;
      border: none;
      background: transparent;
      cursor: pointer;
      color: var(--muted);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .2s
    }

    .view-btn.active {
      background: rgba(201, 168, 76, .15);
      color: var(--gold)
    }

    .view-btn svg {
      width: 16px;
      height: 16px
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 10px 18px;
      border: none;
      border-radius: 10px;
      font-family: 'DM Sans', sans-serif;
      font-size: .85rem;
      font-weight: 600;
      cursor: pointer;
      transition: all .2s;
      white-space: nowrap;
      text-decoration: none
    }

    .btn svg {
      width: 15px;
      height: 15px
    }

    .btn-gold {
      background: linear-gradient(135deg, var(--gold), var(--gold2));
      color: var(--navy);
      box-shadow: 0 4px 16px rgba(201, 168, 76, .25)
    }

    .btn-gold:hover {
      filter: brightness(1.08);
      transform: translateY(-1px)
    }

    .btn-outline {
      background: rgba(255, 255, 255, .06);
      color: var(--muted2);
      border: 1px solid var(--border)
    }

    .btn-outline:hover {
      border-color: var(--gold);
      color: var(--gold);
      background: rgba(201, 168, 76, .07)
    }

    /* ── POSITION COLORS ── */
    .pos-President {
      background: rgba(201, 168, 76, .15);
      color: var(--gold);
      border: 1px solid rgba(201, 168, 76, .25)
    }

    .pos-Vice-President {
      background: rgba(74, 159, 212, .15);
      color: var(--info);
      border: 1px solid rgba(74, 159, 212, .25)
    }

    .pos-Secretary {
      background: rgba(76, 175, 130, .15);
      color: var(--success);
      border: 1px solid rgba(76, 175, 130, .25)
    }

    .pos-Treasurer {
      background: rgba(155, 127, 212, .15);
      color: #9b7fd4;
      border: 1px solid rgba(155, 127, 212, .25)
    }

    .pos-Auditor {
      background: rgba(224, 160, 48, .15);
      color: var(--warn);
      border: 1px solid rgba(224, 160, 48, .25)
    }

    .pos-Director {
      background: rgba(224, 92, 92, .15);
      color: var(--danger);
      border: 1px solid rgba(224, 92, 92, .25)
    }

    .pos-default {
      background: rgba(255, 255, 255, .07);
      color: var(--muted2);
      border: 1px solid var(--border)
    }

    /* ── CARD GRID VIEW ── */
    .cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 16px;
      animation: none
    }

    .cand-card {
      background: var(--navy2);
      border: 1px solid var(--border);
      border-radius: 18px;
      overflow: hidden;
      transition: transform .22s, box-shadow .22s
    }

    .cand-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 16px 48px rgba(0, 0, 0, .4)
    }

    .card-banner {
      height: 72px;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center
    }

    .card-avatar {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      border: 3px solid var(--navy2);
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Playfair Display', serif;
      font-size: 1.5rem;
      font-weight: 700;
      position: absolute;
      bottom: -36px;
      left: 50%;
      transform: translateX(-50%);
      box-shadow: 0 6px 20px rgba(0, 0, 0, .4);
      flex-shrink: 0;
      overflow: hidden
    }

    .card-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover
    }

    .card-info {
      padding: 46px 20px 20px;
      text-align: center
    }

    .card-name {
      font-family: 'Playfair Display', serif;
      font-size: 1.05rem;
      font-weight: 700;
      margin-bottom: 6px
    }

    .card-position {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .5px;
      margin-bottom: 10px
    }

    .card-desc {
      font-size: .78rem;
      color: var(--muted);
      line-height: 1.6;
      margin-bottom: 14px;
      display: -webkit-box;
      -webkit-box-orient: vertical;
      overflow: hidden;
      min-height: 2.6em
    }

    .card-meta {
      display: flex;
      justify-content: center;
      gap: 18px;
      padding-top: 12px;
      border-top: 1px solid var(--border)
    }

    .meta-item {
      text-align: center
    }

    .meta-val {
      font-family: 'Playfair Display', serif;
      font-size: 1rem;
      font-weight: 700
    }

    .meta-label {
      font-size: .65rem;
      color: var(--muted);
      margin-top: 2px
    }

    .card-actions {
      display: flex;
      gap: 8px;
      padding: 12px 20px;
      border-top: 1px solid var(--border);
      background: rgba(255, 255, 255, .02)
    }

    .card-btn {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 8px 10px;
      border: none;
      border-radius: 9px;
      font-family: 'DM Sans', sans-serif;
      font-size: .77rem;
      font-weight: 600;
      cursor: pointer;
      transition: all .2s
    }

    .card-btn svg {
      width: 13px;
      height: 13px
    }

    .card-btn.edit-btn {
      background: rgba(201, 168, 76, .1);
      color: var(--gold);
      border: 1px solid rgba(201, 168, 76, .2)
    }

    .card-btn.edit-btn:hover {
      background: rgba(201, 168, 76, .22)
    }

    .card-btn.del-btn {
      background: rgba(224, 92, 92, .1);
      color: var(--danger);
      border: 1px solid rgba(224, 92, 92, .2)
    }

    .card-btn.del-btn:hover {
      background: rgba(224, 92, 92, .22)
    }

    .card-btn.view-btn {
      background: rgba(74, 159, 212, .1);
      color: var(--info);
      border: 1px solid rgba(74, 159, 212, .2)
    }

    .card-btn.view-btn:hover {
      background: rgba(74, 159, 212, .22)
    }

    /* ── TABLE VIEW ── */
    .table-card {
      background: var(--navy2);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      animation: none
    }

    .table-scroll {
      flex: 1;
      min-height: 0;
      overflow: auto
    }

    .content > .table-card { display: flex; flex-direction: column; flex: 1 0 280px; min-height: 280px; }
    .table-scroll thead { position: sticky; top: 0; z-index: 1; background: var(--navy2); }
    .row-number { color: var(--muted2); font-variant-numeric: tabular-nums; }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: .84rem;
      min-width: 680px
    }

    thead tr {
      background: rgba(255, 255, 255, .03);
      border-bottom: 1px solid var(--border)
    }

    th {
      padding: 12px 16px;
      text-align: left;
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      color: var(--muted);
      white-space: nowrap
    }

    td {
      padding: 13px 16px;
      border-bottom: 1px solid rgba(255, 255, 255, .04);
      vertical-align: middle;
      transition: background .15s
    }

    tr:hover td {
      background: rgba(255, 255, 255, .03)
    }

    tr:last-child td {
      border-bottom: none
    }

    .cand-cell {
      display: flex;
      align-items: center;
      gap: 10px
    }

    .t-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .78rem;
      font-weight: 700;
      flex-shrink: 0;
      overflow: hidden
    }

    .t-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover
    }

    .t-name {
      font-weight: 600;
      font-size: .875rem
    }

    .t-sub {
      font-size: .7rem;
      color: var(--muted);
      margin-top: 1px
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 10px;
      border-radius: 6px;
      font-size: .7rem;
      font-weight: 700;
      white-space: nowrap
    }

    .badge-green {
      background: rgba(76, 175, 130, .15);
      color: var(--success)
    }

    .badge-muted {
      background: rgba(255, 255, 255, .07);
      color: var(--muted)
    }

    .action-btns {
      display: flex;
      gap: 6px
    }

    .icon-btn {
      width: 30px;
      height: 30px;
      border-radius: 7px;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .2s
    }

    .icon-btn svg {
      width: 14px;
      height: 14px
    }

    .icon-btn.view {
      background: rgba(74, 159, 212, .12);
      color: var(--info)
    }

    .icon-btn.view:hover {
      background: rgba(74, 159, 212, .25)
    }

    .icon-btn.edit {
      background: rgba(201, 168, 76, .12);
      color: var(--gold)
    }

    .icon-btn.edit:hover {
      background: rgba(201, 168, 76, .25)
    }

    .icon-btn.del {
      background: rgba(224, 92, 92, .12);
      color: var(--danger)
    }

    .icon-btn.del:hover {
      background: rgba(224, 92, 92, .25)
    }

    .pagination {
      flex-shrink: 0;
      margin-top: auto;
      background: var(--navy2);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 20px;
      border-top: 1px solid var(--border);
      flex-wrap: wrap;
      gap: 10px
    }

    .pag-info {
      font-size: .78rem;
      color: var(--muted)
    }

    .pag-btns {
      display: flex;
      flex-wrap: wrap;
      min-width: 0;
      max-width: 100%;
      gap: 5px
    }

    .pag-info, .pag-btn { font-variant-numeric: tabular-nums; }
    .pag-btn:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
    @media (max-width: 480px) {
      .pag-btns { gap: 3px; }
      .pagination .pag-btn { width: clamp(24px, 6.8vw, 28px); flex-shrink: 0; }
    }

    .pag-btn {
      flex-shrink: 0;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: transparent;
      color: var(--muted2);
      cursor: pointer;
      font-size: .8rem;
      font-family: 'DM Sans', sans-serif;
      transition: all .2s;
      display: flex;
      align-items: center;
      justify-content: center
    }

    .pag-btn:hover {
      border-color: var(--gold);
      color: var(--gold)
    }

    .pag-btn.active {
      background: var(--gold);
      border-color: var(--gold);
      color: var(--navy);
      font-weight: 700
    }

    .pag-btn:disabled {
      opacity: .35;
      cursor: not-allowed
    }

    /* ── EMPTY STATE ── */
    .empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 72px 20px;
      gap: 12px;
      color: var(--muted);
      text-align: center
    }

    .empty-state svg {
      width: 48px;
      height: 48px;
      opacity: .28
    }

    .empty-state p {
      font-size: .85rem;
      line-height: 1.6
    }

    .empty-state .empty-hint {
      font-size: .78rem;
      opacity: .7
    }

    .empty-grid {
      grid-column: 1/-1
    }

    /* ── MODALS ── */
    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .65);
      backdrop-filter: blur(6px);
      z-index: 500;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      opacity: 0;
      pointer-events: none;
      transition: opacity .25s
    }

    .modal-backdrop.show {
      opacity: 1;
      pointer-events: all
    }

    .modal {
      background: var(--navy2);
      border: 1px solid var(--border);
      border-radius: 20px;
      width: 100%;
      max-width: 580px;
      max-height: 92vh;
      overflow-y: auto;
      box-shadow: 0 32px 80px rgba(0, 0, 0, .6);
      transform: translateY(28px) scale(.97);
      transition: transform .3s cubic-bezier(.22, 1, .36, 1), opacity .25s;
      opacity: 0
    }

    .modal-backdrop.show .modal {
      transform: translateY(0) scale(1);
      opacity: 1
    }

    .modal.sm {
      max-width: 390px
    }

    .modal-head {
      padding: 24px 26px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px
    }

    .modal-title {
      font-family: 'Playfair Display', serif;
      font-size: 1.1rem;
      font-weight: 700
    }

    .modal-close {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      border: none;
      background: rgba(255, 255, 255, .07);
      color: var(--muted2);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .2s;
      flex-shrink: 0
    }

    .modal-close:hover {
      background: rgba(224, 92, 92, .2);
      color: var(--danger)
    }

    .modal-body {
      padding: 24px 26px
    }

    .photo-upload {
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 22px;
      padding: 16px;
      background: rgba(255, 255, 255, .04);
      border-radius: 12px;
      border: 1.5px dashed rgba(255, 255, 255, .12);
      cursor: pointer;
      transition: border-color .2s
    }

    .photo-upload:hover {
      border-color: var(--gold)
    }

    .photo-preview {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: var(--navy3);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      overflow: hidden;
      border: 2px solid var(--border)
    }

    .photo-preview svg {
      width: 24px;
      height: 24px;
      color: var(--muted)
    }

    .photo-preview img {
      width: 100%;
      height: 100%;
      object-fit: cover
    }

    .photo-upload-text strong {
      display: block;
      color: var(--muted2);
      font-size: .82rem;
      margin-bottom: 3px
    }

    .photo-upload-text span {
      font-size: .73rem;
      color: var(--muted)
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-bottom: 14px
    }

    .form-row.full {
      grid-template-columns: 1fr
    }

    .field label {
      display: block;
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .8px;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 6px
    }

    .field input,
    .field select,
    .field textarea {
      width: 100%;
      padding: 11px 14px;
      background: rgba(255, 255, 255, .06);
      border: 1.5px solid var(--border);
      border-radius: 10px;
      color: var(--white);
      font-family: 'DM Sans', sans-serif;
      font-size: .875rem;
      outline: none;
      transition: border-color .2s, box-shadow .2s;
      resize: none
    }

    .field input::placeholder,
    .field textarea::placeholder {
      color: rgba(255, 255, 255, .2)
    }

    .field input:focus,
    .field select:focus,
    .field textarea:focus {
      border-color: var(--gold);
      box-shadow: 0 0 0 3px rgba(201, 168, 76, .12)
    }

    .field select option {
      background: var(--navy2)
    }

    .field select:disabled { opacity: .65; cursor: not-allowed; }
    .field-hint { display: block; margin-top: 6px; color: var(--muted2); font-size: .75rem; line-height: 1.5; }
    #save-candidate-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }

    .modal-foot {
      padding: 16px 26px 24px;
      display: flex;
      gap: 10px;
      justify-content: flex-end
    }

    .btn-ghost {
      background: rgba(255, 255, 255, .06);
      color: var(--muted2);
      border: 1px solid var(--border)
    }

    .btn-ghost:hover {
      background: rgba(255, 255, 255, .1)
    }

    .btn-red {
      background: rgba(224, 92, 92, .12);
      color: var(--danger);
      border: 1px solid rgba(224, 92, 92, .2)
    }

    .btn-red:hover {
      background: rgba(224, 92, 92, .25)
    }

    /* profile view modal */
    .profile-banner {
      height: 90px;
      border-radius: 14px 14px 0 0;
      margin: -24px -26px 0;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center
    }

    .profile-avatar-lg {
      width: 84px;
      height: 84px;
      border-radius: 50%;
      border: 4px solid var(--navy2);
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Playfair Display', serif;
      font-size: 1.8rem;
      font-weight: 700;
      position: absolute;
      bottom: -42px;
      left: 50%;
      transform: translateX(-50%);
      box-shadow: 0 8px 24px rgba(0, 0, 0, .4);
      overflow: hidden
    }

    .profile-avatar-lg img {
      width: 100%;
      height: 100%;
      object-fit: cover
    }

    .profile-details {
      padding-top: 54px;
      text-align: center
    }

    .profile-name {
      font-family: 'Playfair Display', serif;
      font-size: 1.25rem;
      font-weight: 700;
      margin-bottom: 6px
    }

    .profile-pos {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 14px;
      border-radius: 20px;
      font-size: .75rem;
      font-weight: 700;
      margin-bottom: 14px
    }

    .profile-bio {
      font-size: .84rem;
      color: var(--muted);
      line-height: 1.7;
      text-align: left;
      background: rgba(255, 255, 255, .04);
      padding: 14px;
      border-radius: 10px;
      margin-bottom: 16px
    }

    .profile-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px
    }

    .profile-field {
      background: rgba(255, 255, 255, .04);
      border-radius: 10px;
      padding: 12px 14px
    }

    .profile-field-label {
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: .8px;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 4px
    }

    .profile-field-val {
      font-size: .84rem;
      font-weight: 500
    }

    /* confirm */
    .confirm-center {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      padding: 4px 0 2px
    }

    .confirm-icon {
      width: 58px;
      height: 58px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 14px;
      background: rgba(224, 92, 92, .12)
    }

    .confirm-icon svg {
      color: var(--danger);
      width: 26px;
      height: 26px
    }

    .confirm-title {
      font-family: 'Playfair Display', serif;
      font-size: 1.05rem;
      font-weight: 700;
      margin-bottom: 8px
    }

    .confirm-msg {
      font-size: .83rem;
      color: var(--muted);
      line-height: 1.65
    }

    /* ── TOAST ── */
    .toast-wrap {
      position: fixed;
      top: 78px;
      right: 24px;
      z-index: 999;
      display: flex;
      flex-direction: column;
      gap: 8px;
      pointer-events: none
    }

    .toast {
      background: var(--navy3);
      border: 1px solid var(--border);
      border-radius: 11px;
      padding: 12px 16px;
      display: flex;
      align-items: center;
      gap: 9px;
      font-size: .83rem;
      min-width: 230px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, .4);
      transform: translateX(130%);
      transition: transform .35s cubic-bezier(.22, 1, .36, 1)
    }

    .toast.show {
      transform: translateX(0)
    }

    .toast-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      flex-shrink: 0
    }

    .toast.success .toast-dot {
      background: var(--success)
    }

    .toast.error .toast-dot {
      background: var(--danger)
    }

    .toast.warn .toast-dot {
      background: var(--warn)
    }

    .toast.info .toast-dot {
      background: var(--info)
    }

    /* ── OVERLAY ── */
    .overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .6);
      z-index: 150;
      backdrop-filter: blur(4px)
    }

    /* ── RESPONSIVE ── */
    @media(max-width:768px) {
      .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        transform: translateX(-100%);
        box-shadow: 8px 0 40px rgba(0, 0, 0, .5)
      }

      .sidebar.open {
        transform: translateX(0)
      }

      .overlay {
        display: block;
        pointer-events: none;
        opacity: 0;
        transition: opacity .3s
      }

      .overlay.show {
        pointer-events: all;
        opacity: 1
      }

      .hamburger {
        display: flex
      }

      .content {
        padding: 16px
      }

      .summary-strip {
        grid-template-columns: 1fr 1fr
      }

      .header {
        padding: 0 16px
      }

      .form-row {
        grid-template-columns: 1fr
      }

      .cards-grid {
        grid-template-columns: 1fr
      }

      .profile-grid {
        grid-template-columns: 1fr
      }
    }

    @media(max-width:480px) {
      .s-val {
        font-size: 1.4rem
      }

      .toolbar {
        gap: 8px
      }
    }
  
    /* ── Layout fix ──────────────────────────────────────────
       .content is a flex column, and flex items shrink by default.
       Once the page grew taller than the viewport every section was
       squashed, and any card with overflow:hidden clipped its own
       text (the welcome hero showed the name but hid the meta line).
       Sections keep their natural height; .content already scrolls. */
    .content > * { flex-shrink: 0; }
    #grid-view { grid-template-columns: minmax(0, 1fr); }
    .candidate-context, .position-group {
      border: 1px solid var(--border); border-radius: 16px; background: var(--navy2); padding: 20px; min-width: 0;
    }
    .candidate-context { margin-bottom: 18px; }
    .candidate-context h2 { font-size: 1.15rem; overflow-wrap: anywhere; margin: 6px 0; }
    .candidate-context p, .position-group p { color: var(--muted2); font-size: .85rem; line-height: 1.6; }
    .candidate-context .context-label { color: var(--gold); font-size: .75rem; text-transform: uppercase; letter-spacing: .08em; }
    .position-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
    .position-head h3 { font-size: 1rem; overflow-wrap: anywhere; }
    .position-head > div { min-width: 0; flex: 1 1 160px; }
    .position-candidates { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr)); gap: 16px; }
    .position-empty { padding: 18px; border: 1px dashed var(--border); border-radius: 12px; }
    .candidate-context a, .position-empty a { color: var(--gold); }
    .candidate-context button { margin-top: 8px; }
    #candidate-retry[hidden] { display: none !important; }
    .btn:disabled { opacity: .5; cursor: not-allowed; }
    @media(max-width:480px) {
      .candidate-context, .position-group { padding: 14px; }
    }
    .year-section-fields { border:1px solid var(--border); border-radius:12px; padding:14px; margin-bottom:18px; min-width:0; }
    .year-section-fields legend { padding:0 6px; font-size:.85rem; font-weight:600; }
    .year-section-fields .form-row { margin-bottom:0; }
    #candidate-year-section-fields[hidden], .hide-candidate-academics .candidate-academic-column { display:none !important; }
    .candidate-academic-label { color:var(--muted2); font-size:.8rem; overflow-wrap:anywhere; margin-top:8px; }

    /* Candidate editor: two clear groups with a compact shared-photo control. */
    #cand-modal > .modal { max-width:800px; border-radius:18px; }
    #cand-modal .modal-head { gap:16px; }
    #cand-modal .candidate-title-wrap { flex:1; min-width:0; }
    #cand-modal .modal-title { font-size:1.3rem; }
    #cand-modal .candidate-subtitle { margin:6px 0 0; color:var(--muted2); font-size:.8rem; line-height:1.5; }
    #cand-modal .candidate-form-layout { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:20px; align-items:stretch; background:var(--navy); }
    #cand-modal .candidate-form-column { display:grid; align-content:start; gap:16px; min-width:0; padding:20px; background:var(--navy2); border:1px solid var(--border); border-radius:14px; }
    #cand-modal .candidate-form-heading { margin:0; padding-bottom:12px; border-bottom:1px solid var(--border); font-size:.95rem; font-weight:600; color:var(--white); }
    #cand-modal .field label { font-size:.78rem; font-weight:500; letter-spacing:0; text-transform:none; color:var(--muted2); margin-bottom:7px; }
    #cand-modal .field, #cand-modal .field input, #cand-modal .field select { min-width:0; }
    #cand-modal .field input, #cand-modal .field select, #cand-modal .field textarea { padding:10px 12px; border-width:1px; border-radius:8px; min-height:42px; }
    #cand-modal .field input[readonly] { background:var(--navy); color:var(--muted2); }
    #cand-modal .field select:disabled { opacity:1; background:var(--navy); color:var(--muted2); }
    #cand-modal .field-hint { font-size:.72rem; line-height:1.5; }
    #cand-modal .field-hint:empty { display:none; }
    #cand-modal .form-row { margin:0; gap:12px; }
    #cand-modal .year-section-fields { margin:0; padding:12px; border-radius:10px; background:var(--navy); }
    #cand-modal .year-section-fields legend { font-size:.78rem; font-weight:500; color:var(--muted2); }
    #cand-modal #f-desc { min-height:100px; line-height:1.5; }
    #cand-modal .candidate-optional { font-weight:400; color:var(--muted); font-size:.7rem; margin-left:5px; }
    #cand-modal .candidate-registration { margin:0; font-size:.73rem; color:var(--muted2); }
    #cand-modal .candidate-registration a { color:var(--gold); text-underline-offset:3px; }
    #cand-modal .candidate-photo-card { padding-top:16px; border-top:1px solid var(--border); }
    #cand-modal .candidate-photo-row { display:flex; align-items:center; gap:14px; }
    #cand-modal .photo-preview { width:64px; height:64px; border:1px solid var(--border); }
    #cand-modal .candidate-photo-actions { display:grid; gap:8px; min-width:0; }
    #cand-modal .candidate-photo-actions strong { font-size:.8rem; font-weight:500; color:var(--muted2); }
    #cand-modal .candidate-photo-picker { display:inline-flex; align-items:center; justify-content:center; position:relative; min-height:40px; padding:8px 14px; border:1px solid var(--border); border-radius:8px; font-size:.76rem; font-weight:600; color:var(--white); background:var(--navy3); cursor:pointer; overflow:hidden; }
    #cand-modal .candidate-photo-picker:hover { border-color:var(--gold); }
    #cand-modal .candidate-photo-picker:focus-within { outline:2px solid var(--gold); outline-offset:3px; }
    #cand-modal .candidate-photo-picker input { position:absolute; inset:0; width:100%; height:100%; opacity:0; cursor:pointer; }
    #cand-modal .candidate-photo-picker:has(input:disabled) { opacity:.5; cursor:wait; }
    #cand-modal #photo-hint { margin-top:10px; }
    #cand-modal .candidate-photo-scope { font-size:.72rem; color:var(--muted2); line-height:1.5; margin:6px 0 0; }
    #cand-modal.is-editing .candidate-search-field, #cand-modal.is-editing .candidate-registration { display:none; }
    #cand-modal .candidate-position-value { min-height:42px; padding:10px 12px; background:var(--navy); border:1px solid var(--border); border-radius:8px; color:var(--white); font-size:.875rem; line-height:1.5; overflow-wrap:anywhere; }
    #cand-modal :is(#f-position,#candidate-position-value)[hidden] { display:none; }
    #cand-modal .modal-foot { border-top:1px solid var(--border); }
    #cand-modal.show ~ #toast-wrap .toast-notification { display:none; }
    @media (max-width:899px) {
      #cand-modal > .modal { max-width:640px; }
      #cand-modal .candidate-form-layout { grid-template-columns:minmax(0,1fr); gap:14px; }
    }
    @media (max-width:480px) {
      #cand-modal .candidate-form-column { padding:14px; gap:14px; }
      #cand-modal .modal-title { font-size:1.1rem; }
      #cand-modal .candidate-subtitle { font-size:.74rem; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/responsive.css?v=20261019-mobile-layout" />
  <script src="../assets/js/viewport.js?v=20260911-layout1" defer></script>
  <link rel="stylesheet" href="../assets/css/themes.css?v=20260915">
  <script src="../assets/js/appearance.js?v=20261110-settings"></script>
  <link rel="stylesheet" href="../assets/css/navigation.css?v=20261019-sidebar-first-paint" />
  <script src="../assets/js/navigation.js?v=20261110-shared-profile"></script>
  <script src="../assets/js/branding.js?v=20261019-sidebar-stable"></script>
  <link rel="stylesheet" href="../assets/css/tables.css?v=20260918-scroll-position" />
  <link rel="stylesheet" href="../assets/css/responsive-tables.css?v=20261112-reference-layout" />
  <script src="../assets/js/responsive-tables.js?v=20261112-table-layout" defer></script>
  <link rel="stylesheet" href="../assets/css/candidate-photo-preview.css?v=20261111" />
  <script src="../assets/js/candidate-photo-preview.js?v=20261111" defer></script>
  <link rel="stylesheet" href="../assets/css/compact-ui.css?v=20261113-clean-logo" />
  <link rel="stylesheet" href="../assets/css/candidate-profile.css?v=20261112-compact" />
</head>

<body>
  <div class="overlay" id="overlay" onclick="closeSidebar()"></div>

  <!-- ── ADD / EDIT MODAL ── -->
  <div class="modal-backdrop" id="cand-modal">
    <div class="modal">
      <div class="modal-head">
        <div class="candidate-title-wrap"><div class="modal-title" id="modal-title">Add New Candidate</div><p class="candidate-subtitle">Review the voter and complete their election details.</p></div>
        <button aria-label="Close" class="modal-close" onclick="closeModal('cand-modal')">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
          </svg>
        </button>
      </div>
      <div class="modal-body candidate-form-layout">
        <input type="hidden" id="edit-id" />
        <section class="candidate-form-column" aria-labelledby="candidate-voter-heading">
          <h2 id="candidate-voter-heading" class="candidate-form-heading">Voter profile</h2>
          <div class="field candidate-search-field">
            <label for="f-member-search">Find a voter</label>
            <input type="search" id="f-member-search" placeholder="Search name or member ID" oninput="filterCandidateMembers()" />
          </div>
          <div class="field">
            <label for="f-member">Registered voter</label>
            <select id="f-member" required disabled onchange="selectCandidateMember()" aria-describedby="member-hint"><option value="">Loading voters…</option></select>
            <p class="field-hint" id="member-hint" role="status">Choose an existing voter to fill in their name.</p>
          </div>
          <div class="form-row">
            <div class="field"><label for="f-first">First name</label><input type="text" id="f-first" placeholder="From voter record" data-input="name" maxlength="100" readonly /></div>
            <div class="field"><label for="f-last">Last name</label><input type="text" id="f-last" placeholder="From voter record" data-input="name" maxlength="100" readonly /></div>
          </div>
          <p class="candidate-registration">New voter? <a href="voter-management.php">Register in Voter Management</a></p>
          <div class="candidate-photo-card">
            <div class="candidate-photo-row">
              <div class="photo-preview" id="photo-preview">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </div>
              <div class="candidate-photo-actions">
                <strong>Profile picture <span class="candidate-optional">Optional</span></strong>
                <label class="candidate-photo-picker" for="f-photo">Choose picture
                  <input type="file" id="f-photo" accept="image/jpeg,image/png,image/webp" onchange="chooseCandidatePhoto(this)" aria-describedby="photo-hint candidate-photo-scope" />
                </label>
              </div>
            </div>
            <p class="field-hint" id="photo-hint" role="status">JPG, PNG or WebP · Up to 2 MB</p>
            <p class="candidate-photo-scope" id="candidate-photo-scope">Shared with the voter’s account and all candidacies. Changes apply when you save.</p>
          </div>
        </section>
        <section class="candidate-form-column" aria-labelledby="candidate-election-heading">
          <h2 id="candidate-election-heading" class="candidate-form-heading">Election details</h2>
          <div class="field">
            <label for="f-election">Selected election</label>
            <select id="f-election" required disabled onchange="loadCandidatePositions()" aria-describedby="election-hint"><option value="">— Select Election —</option></select>
            <span class="field-hint" id="election-hint">Select an election to see its positions.</span>
          </div>
          <div class="form-row">
            <div class="field"><label for="f-position" id="candidate-position-label">Position</label>
              <select id="f-position" required disabled onchange="updateCandidateSaveState()" aria-describedby="position-hint"><option value="">— Select Position —</option></select>
              <div id="candidate-position-value" class="candidate-position-value" aria-labelledby="candidate-position-label" aria-describedby="position-hint" hidden></div>
              <span class="field-hint" id="position-hint" role="status">Select an election first.</span>
            </div>
            <div class="field"><label for="f-status">Status</label><select id="f-status"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
          </div>
          <fieldset id="candidate-year-section-fields" class="year-section-fields">
            <legend>Year &amp; Section</legend>
            <div class="form-row">
              <div class="field"><label for="f-year">Year</label><input type="text" id="f-year" placeholder="e.g. 2nd Year" maxlength="32" required /></div>
              <div class="field"><label for="f-section">Section</label><input type="text" id="f-section" placeholder="e.g. A" maxlength="100" required /></div>
            </div>
          </fieldset>
          <div class="field"><label for="f-desc">Platform / Description <span class="candidate-optional">Optional</span></label><textarea id="f-desc" rows="3" placeholder="Share the candidate’s background, goals, and plans…"></textarea></div>
        </section>
      </div>
      <div class="modal-foot">
        <button class="btn btn-ghost" onclick="closeModal('cand-modal')">Cancel</button>
        <button class="btn btn-gold" id="save-candidate-btn" disabled onclick="saveCandidate()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="20 6 9 17 4 12" />
          </svg>
          Save Candidate
        </button>
      </div>
    </div>
  </div>

  <!-- ── VIEW PROFILE MODAL ── -->
  <div class="modal-backdrop" id="view-modal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="candidate-profile-title">
      <div class="modal-head">
        <div class="modal-title" id="candidate-profile-title">Candidate Profile</div>
        <button aria-label="Close" class="modal-close" onclick="closeModal('view-modal')">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
          </svg>
        </button>
      </div>
      <div class="modal-body" id="view-body"></div>
      <div class="modal-foot">
        <button class="btn btn-ghost" onclick="closeModal('view-modal')">Close</button>
        <button class="btn btn-gold" id="view-edit-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
          </svg>
          Edit Candidate
        </button>
      </div>
    </div>
  </div>

  <!-- ── CONFIRM DELETE MODAL ── -->
  <div class="modal-backdrop" id="confirm-modal">
    <div class="modal sm">
      <div class="modal-head">
        <div class="modal-title">Remove Candidate</div>
        <button aria-label="Close" class="modal-close" onclick="closeModal('confirm-modal')">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
          </svg>
        </button>
      </div>
      <div class="modal-body">
        <div class="confirm-center">
          <div class="confirm-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6" />
              <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
              <path d="M10 11v6" />
              <path d="M14 11v6" />
              <path d="M9 6V4h6v2" />
            </svg>
          </div>
          <div class="confirm-title" id="candidate-remove-title">Remove this candidate?</div>
          <div class="confirm-msg" id="candidate-remove-message"></div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-ghost" onclick="closeModal('confirm-modal')">Cancel</button>
        <button class="btn btn-red" onclick="confirmDelete()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
            <polyline points="3 6 5 6 21 6" />
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
          </svg>
          Yes, Remove
        </button>
      </div>
    </div>
  </div>

  <div class="toast-wrap" id="toast-wrap"></div>

  <div class="app">
    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-logo">
        <div class="logo-mark"><svg viewBox="0 0 24 24">
            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
          </svg></div>
        <div class="logo-text">Coop<span>Vote</span></div>
      </div>

      <div class="sidebar-section">Main</div>
      <a class="nav-item" href="dashboard.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="7" />
          <rect x="14" y="3" width="7" height="7" />
          <rect x="3" y="14" width="7" height="7" />
          <rect x="14" y="14" width="7" height="7" />
        </svg>
        Dashboard
      </a>

      <div class="sidebar-section">Management</div>
      <a class="nav-item" href="voter-management.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
          <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
          <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
        Voter Management
      </a>
      <a class="nav-item active" href="candidate-management.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
          <circle cx="12" cy="7" r="4" />
        </svg>
        Candidate Management
      </a>
      <a class="nav-item" href="election-management.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="4" width="18" height="18" rx="2" />
          <line x1="16" y1="2" x2="16" y2="6" />
          <line x1="8" y1="2" x2="8" y2="6" />
          <line x1="3" y1="10" x2="21" y2="10" />
        </svg>
        Election Management
      </a>

      <div class="sidebar-section">Analytics</div>
      <a class="nav-item" href="vote-monitoring.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
        </svg>
        Vote Monitoring
      </a>
      <a class="nav-item" href="results.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="20" x2="18" y2="10" />
          <line x1="12" y1="20" x2="12" y2="4" />
          <line x1="6" y1="20" x2="6" y2="14" />
        </svg>
        Results
      </a>
      <a class="nav-item" href="reports.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
          <polyline points="14 2 14 8 20 8" />
          <line x1="16" y1="13" x2="8" y2="13" />
          <line x1="16" y1="17" x2="8" y2="17" />
        </svg>
        Reports
      </a>

      <div class="sidebar-section">System</div>
      <a class="nav-item" href="security-logs.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        </svg>
        Security &amp; Logs
      </a>

      <div class="sidebar-footer">
        <div class="admin-card">
          <div class="admin-avatar" id="sidebar-avatar">?</div>
          <div class="admin-info">
            <div class="admin-name" id="sidebar-name">—</div>
            <div class="admin-role" id="sidebar-id">Administrator</div>
          </div>
        </div>
      </div>
    </aside>
    <script>CoopVoteNavigation.bootstrap(<?= $sidebarBootstrap ?>);</script>

    <!-- MAIN -->
    <div class="main">
      <header class="header">
        <button aria-label="Open the menu" class="hamburger" onclick="toggleSidebar()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="6" x2="21" y2="6" />
            <line x1="3" y1="12" x2="21" y2="12" />
            <line x1="3" y1="18" x2="21" y2="18" />
          </svg>
        </button>
        <div class="header-title">
          <h1>Candidate Management</h1>
          <p>Manage election candidates, positions, and profiles</p>
        </div>
        <div class="header-actions">
          <button class="hbtn" title="Notifications">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
              <path d="M13.73 21a2 2 0 0 1-3.46 0" />
            </svg>
          </button>
          <button class="hbtn" title="Logout" onclick="CoopVote.logout()"
            style="border:1px solid rgba(224,92,92,.2);color:var(--danger)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
          </button>
        </div>
      </header>

      <div class="content">

        <section class="candidate-context" aria-labelledby="candidate-election-title">
          <div class="context-label">Selected election</div>
          <h2 id="candidate-election-title">Candidate directory</h2>
          <p id="candidate-election-status"></p>
          <p id="candidate-setup-hint" role="status">Candidates and positions below belong only to the selected election.</p>
          <button class="btn btn-outline" id="candidate-retry" hidden onclick="loadCandidates()">Retry loading</button>
        </section>

        <!-- SUMMARY -->
        <div class="summary-strip">
          <div class="s-card">
            <div class="s-icon gold"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
              </svg></div>
            <div>
              <div class="s-val empty" id="sc-total">—</div>
              <div class="s-label">Total Candidates</div>
            </div>
          </div>
          <div class="s-card">
            <div class="s-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon
                  points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
              </svg></div>
            <div>
              <div class="s-val empty" id="sc-positions">—</div>
              <div class="s-label">Positions With Candidates</div>
            </div>
          </div>
          <div class="s-card">
            <div class="s-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
              </svg></div>
            <div>
              <div class="s-val empty" id="sc-active">—</div>
              <div class="s-label">Active Candidates</div>
            </div>
          </div>
          <div class="s-card">
            <div class="s-icon purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="8" r="6" />
                <path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11" />
              </svg></div>
            <div>
              <div class="s-val empty" id="sc-empty">—</div>
              <div class="s-label">Positions Without Candidates</div>
            </div>
          </div>
        </div>

        <!-- TOOLBAR -->
        <div class="toolbar candidate-toolbar">
          <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8" />
              <line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
            <input type="text" id="search-input" placeholder="Search by name or position…" oninput="applyFilters()" />
          </div>
          <select class="filter-select" id="election-filter" aria-label="Filter candidates by election" onchange="changeCandidateElection()">
            <option value="">Loading elections…</option>
          </select>
          <select class="filter-select" id="pos-filter" aria-label="Filter candidates by position" onchange="applyFilters()">
            <option value="">All Positions</option>
          </select>
          <select class="filter-select" id="status-filter" aria-label="Filter candidates by status" onchange="applyFilters()">
            <option value="">All Status</option>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
          <div class="candidate-actions">
          <div class="view-toggle">
            <button class="view-btn" id="grid-btn" aria-pressed="false" onclick="setView('grid')" title="Grouped by position" aria-label="Grouped by position">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" />
                <rect x="14" y="3" width="7" height="7" />
                <rect x="3" y="14" width="7" height="7" />
                <rect x="14" y="14" width="7" height="7" />
              </svg>
            </button>
            <button class="view-btn active" id="list-btn" aria-pressed="true" onclick="setView('list')" title="List view">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="8" y1="6" x2="21" y2="6" />
                <line x1="8" y1="12" x2="21" y2="12" />
                <line x1="8" y1="18" x2="21" y2="18" />
                <line x1="3" y1="6" x2="3.01" y2="6" />
                <line x1="3" y1="12" x2="3.01" y2="12" />
                <line x1="3" y1="18" x2="3.01" y2="18" />
              </svg>
            </button>
          </div>
          <button class="btn btn-outline" onclick="exportCandidates()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
              <polyline points="7 10 12 15 17 10" />
              <line x1="12" y1="15" x2="12" y2="3" />
            </svg>
            Export
          </button>
          <button class="btn btn-gold" id="add-candidate-btn" onclick="openAdd()" disabled>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="12" y1="5" x2="12" y2="19" />
              <line x1="5" y1="12" x2="19" y2="12" />
            </svg>
            Add Candidate
          </button>
          </div>
        </div>

        <!-- GRID VIEW -->
        <div class="cards-grid" id="grid-view" style="display:none"></div>

        <!-- TABLE VIEW -->
        <div class="table-card" id="list-view">
          <div class="table-scroll">
            <table class="cv-data-table cv-candidates">
              <colgroup><col style="width:5%"><col style="width:29%"><col style="width:17%"><col class="candidate-academic-column" style="width:15%"><col style="width:7%"><col style="width:12%"><col style="width:15%"></colgroup>
              <thead>
                <tr>
                  <th scope="col">No.</th>
                  <th>Candidate</th>
                  <th>Position</th>
                  <th class="candidate-academic-column">Year &amp; Section</th>
                  <th>Votes</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="cand-tbody"></tbody>
            </table>
          </div>
          <div class="pagination">
            <div class="pag-info" id="pag-info">No records</div>
            <div class="pag-btns" id="pag-btns"></div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script src="../assets/js/validation.js?v=20260911-required2"></script>
  <script src="../assets/js/app.js?v=20261113-top-right-toasts"></script>
  <script>
    /* ─────────────────────────────────────────
       STATE
    ───────────────────────────────────────── */
    let allCandidates = [];   // populated by loadCandidates()
    let candidateFilterElections = [];
    let candidateListLoading = false;
    let candidateListError = '';
    let showCandidateYearSection = true;
    let filtered = [];
    let currentView = 'list';
    let deleteTarget = null;
    const PAGE_SIZE = 10;
    let currentPage = 1;

    const AVATAR_PALETTE = [
      { bg: '#1e2d5a', fg: '#7ca4e8' }, { bg: '#1a3a2e', fg: '#5dc99a' },
      { bg: '#3a1e1e', fg: '#e87c7c' }, { bg: '#3a2e10', fg: '#d4a84c' },
      { bg: '#2a1e3a', fg: '#b07cd4' }, { bg: '#1a2e3a', fg: '#5cb8d4' },
      { bg: '#1e3a2a', fg: '#7cd4aa' }, { bg: '#3a261e', fg: '#d48c5c' },
    ];

    const POS_COLORS = {
      President: '#c9a84c',
      'Vice President': '#4a9fd4',
      Secretary: '#4caf82',
      Treasurer: '#9b7fd4',
      Auditor: '#e0a030',
      Director: '#e05c5c',
    };

    /* ─────────────────────────────────────────
       SIDEBAR
    ───────────────────────────────────────── */
    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('open');
      document.getElementById('overlay').classList.toggle('show');
    }
    function closeSidebar() {
      document.getElementById('sidebar').classList.remove('open');
      document.getElementById('overlay').classList.remove('show');
    }

    /* ─────────────────────────────────────────
       HELPERS
    ───────────────────────────────────────── */
    function initials(first, last) {
      return ((first?.[0] || '') + (last?.[0] || '')).toUpperCase() || '?';
    }
    function avatarColor(id) {
      return AVATAR_PALETTE[(typeof id === 'number' ? id : 0) % AVATAR_PALETTE.length];
    }
    function posClass(position) {
      const key = (position || '').replace(/\s+/g, '-');
      return `pos-${key}` in document.styleSheets[0]?.cssRules ? `pos-${key}` : 'pos-default';
    }
    function posColor(position) {
      return POS_COLORS[position] || '#a8b8cc';
    }
    function avatarHTML(c, size = 'sm') {
      const { bg, fg } = avatarColor(c.id || 0);
      const cls = size === 'lg' ? 'profile-avatar-lg' : (size === 'md' ? 'card-avatar' : 't-avatar');
      if (c.photoUrl) {
        return `<div class="${cls}" style="background:${bg}"><img src="${esc(c.photoUrl)}" alt="${esc(c.first)}"/></div>`;
      }
      return `<div class="${cls}" style="background:${bg};color:${fg}">${initials(c.first, c.last)}</div>`;
    }
    function statusBadge(s) {
      return s === 'Active'
        ? `<span class="badge badge-green">Active</span>`
        : `<span class="badge badge-muted">Inactive</span>`;
    }

    /* ─────────────────────────────────────────
       LOAD CANDIDATES
       ─────────────────────────────────────────
       Replace with a real API call:
       fetch('/api/candidates')
         .then(r => r.json())
         .then(data => initCandidates(data))
         .catch(() => initCandidates([]));
    
       Each candidate object should have:
       {
         id, first, last, position, section, year,
         desc, votes, status, years, photoUrl
       }
    ───────────────────────────────────────── */
    let candidateListRequest = 0;
    async function loadCandidates() {
      const request = ++candidateListRequest;
      const wanted = document.getElementById('election-filter').value || new URLSearchParams(location.search).get('election') || '';
      candidateListLoading = true;
      candidateListError = '';
      applyFilters();
      try {
        const [elections, display] = await Promise.all([CoopVote.api('elections'), CoopVote.api('candidates/display-settings')]);
        if (request !== candidateListRequest) return;
        applyCandidateDisplaySettings(display);
        const selected = elections.find(e => String(e.id) === wanted) || elections.find(e => e.status === 'Active') || elections[0];
        const id = selected ? String(selected.id) : '';
        const link = new URL(location.href);
        if (id) link.searchParams.set('election', id); else link.searchParams.delete('election');
        history.replaceState(null, '', link.href);
        initCandidates(allCandidates, elections, id);
        if (!id) { candidateListLoading = false; initCandidates([], elections, ''); return; }
        const candidates = await CoopVote.api('candidates&election=' + encodeURIComponent(id));
        if (request !== candidateListRequest || document.getElementById('election-filter').value !== id) return;
        candidateListLoading = false;
        initCandidates(candidates, elections, id);
      } catch (err) {
        if (request !== candidateListRequest) return;
        candidateListLoading = false;
        candidateListError = err.message || 'Could not load candidates. Retry to view the current setup.';
        allCandidates = [];
        applyFilters();
      }
    }

    let availableElections = [];
    let availablePositions = [];
    let availableMembers = [];
    let candidateSaving = false;
    let candidatePhotoChange;
    let candidatePhotoMemberId = '';
    let candidatePhotoReading = false;
    let candidatePhotoRevision = 0;

    function resetCandidatePhoto() {
      candidatePhotoRevision++;
      candidatePhotoChange = undefined;
      candidatePhotoReading = false;
      document.getElementById('f-photo').value = '';
      document.getElementById('photo-hint').textContent = 'JPG, PNG or WebP · Up to 2 MB';
    }

    function renderCandidatePhoto() {
      const member = availableMembers.find(m => String(m.id) === document.getElementById('f-member').value);
      const current = allCandidates.find(c => String(c.id) === document.getElementById('edit-id').value);
      const url = candidatePhotoChange === undefined ? (current?.photoUrl || member?.photoUrl)
        : candidatePhotoChange === null ? member?.photoUrl : candidatePhotoChange;
      document.getElementById('photo-preview').innerHTML = url
        ? `<img src="${esc(url)}" alt="Candidate photo preview" style="width:100%;height:100%;object-fit:cover;border-radius:50%" />`
        : '<span aria-hidden="true">—</span>';
    }

    async function chooseCandidatePhoto(input) {
      if (candidateSaving) return;
      const revision = ++candidatePhotoRevision;
      const file = input.files?.[0];
      candidatePhotoReading = false;
      if (!file) { updateCandidateSaveState(); return; }
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size === 0 || file.size > 2 * 1024 * 1024) {
        input.value = '';
        document.getElementById('photo-hint').textContent = 'Choose a JPG, PNG or WebP picture up to 2 MB.';
        updateCandidateSaveState();
        return;
      }
      candidatePhotoReading = true;
      updateCandidateSaveState();
      try {
        const data = await new Promise((resolve, reject) => {
          const reader = new FileReader();
          reader.onload = () => resolve(reader.result);
          reader.onerror = () => reject(new Error('Could not read this picture.'));
          reader.readAsDataURL(file);
        });
        if (revision !== candidatePhotoRevision) return;
        candidatePhotoChange = data;
        renderCandidatePhoto();
        document.getElementById('photo-hint').textContent = 'New photo selected. Save Candidate to apply it.';
      } catch (error) {
        if (revision === candidatePhotoRevision) document.getElementById('photo-hint').textContent = 'Could not read this picture. Choose it again.';
      } finally {
        if (revision === candidatePhotoRevision) { candidatePhotoReading = false; updateCandidateSaveState(); }
      }
    }

    let candidateOptionsRequest = 0;
    let candidatePositionsRequest = 0;
    let candidatePositionsLoading = false;

    async function loadCandidateOptions(electionId = '', positionId = '', memberId = '') {
      const request = ++candidateOptionsRequest;
      ++candidatePositionsRequest;
      candidatePositionsLoading = false;
      const electionSelect = document.getElementById('f-election');
      availableElections = [];
      availablePositions = [];
      availableMembers = [];
      const memberSelect = document.getElementById('f-member');
      memberSelect.disabled = true;
      memberSelect.innerHTML = '<option value="">Loading voters…</option>';
      document.getElementById('f-member-search').value = '';
      document.getElementById('f-member-search').disabled = true;
      electionSelect.disabled = true;
      electionSelect.innerHTML = '<option value="">Loading elections…</option>';
      document.getElementById('election-hint').textContent = 'Loading elections and positions…';
      updatePositionOptions();
      try {
        const [elections, members] = await Promise.all([
          CoopVote.api('elections'), CoopVote.api('candidates/eligible')
        ]);
        if (request !== candidateOptionsRequest) return;
        availableElections = elections.filter(e => String(e.id) === String(electionId));
        electionSelect.innerHTML = '<option value="">— Select Election —</option>' +
          availableElections.map(e => `<option value="${esc(e.id)}">${esc(e.title)}</option>`).join('');
        electionSelect.value = String(electionId);
        const editing = !!document.getElementById('edit-id').value;
        availableMembers = members.filter(m => (editing && String(m.id) === String(memberId)) || !(m.candidateElectionIds || []).some(id => String(id) === String(electionId)));
        const current = allCandidates.find(c => String(c.id) === document.getElementById('edit-id').value);
        if (editing && current && !members.some(m => String(m.id) === String(memberId))) {
          availableMembers.push({ id: current.memberId, name: [current.first, current.last].join(' '), memberNo: current.memberNo || '',
            first: current.first, last: current.last, photoUrl: current.photoUrl });
        }
        filterCandidateMembers(memberId);
        memberSelect.disabled = editing || !availableMembers.length;
        document.getElementById('f-member-search').disabled = editing || !members.length;
        await selectCandidateMember(positionId);
        if (request !== candidateOptionsRequest) return;
        document.getElementById('member-hint').textContent = editing
          ? 'Linked voter. Update their name in Voter Management.'
          : members.length ? 'Name is filled from the selected voter record.'
          : 'No active voters found. Register or activate the voter in Voter Management first.';
      } catch (err) {
        if (request !== candidateOptionsRequest) return;
        electionSelect.innerHTML = '<option value="">Could not load elections</option>';
        memberSelect.innerHTML = '<option value="">Could not load voters</option>';
        document.getElementById('member-hint').textContent = 'Close and reopen this form to reload voters.';
        document.getElementById('election-hint').textContent = 'Close and reopen this form to try again.';
        CoopVote.toast(err.message || 'Could not load elections and positions.', 'error');
      }
    }

    function filterCandidateMembers(memberId = document.getElementById('f-member').value) {
      const select = document.getElementById('f-member');
      const query = document.getElementById('f-member-search').value.trim().toLocaleLowerCase();
      // Search plain text; option labels are escaped when rendered below.
      const visible = availableMembers.filter(m => String(m.id) === String(memberId) ||
        [m.name, m.memberNo || ''].join(' ').toLocaleLowerCase().includes(query));
      select.innerHTML = '<option value="">— Select Existing Voter —</option>' + visible.map(m =>
        `<option value="${esc(m.id)}">${esc(m.name)} — ${esc(m.memberNo || 'No member number')}</option>`).join('');
      select.value = String(memberId);
      updateCandidateSaveState();
    }

    function selectCandidateMember(positionId = document.getElementById('f-position').value) {
      const member = availableMembers.find(m => String(m.id) === document.getElementById('f-member').value);
      const nextMemberId = String(member?.id || '');
      if (candidatePhotoMemberId !== nextMemberId) resetCandidatePhoto();
      candidatePhotoMemberId = nextMemberId;
      ['first', 'last'].forEach(key => {
        document.getElementById('f-' + key).value = member?.[key] ?? '';
      });
      renderCandidatePhoto();
      return updateCandidateElectionOptions(positionId);
    }

    function candidateElectionAllowed(electionId) {
      const editId = document.getElementById('edit-id').value;
      if (editId) {
        const current = allCandidates.find(c => String(c.id) === editId);
        return !current || String(current.electionId) === String(electionId);
      }
      const member = availableMembers.find(m => String(m.id) === document.getElementById('f-member').value);
      return !(member?.candidateElectionIds || []).some(id => String(id) === String(electionId));
    }

    function updateCandidateElectionOptions(positionId = '') {
      const select = document.getElementById('f-election');
      const previous = select.value;
      const editing = !!document.getElementById('edit-id').value;
      const elections = availableElections.filter(e => candidateElectionAllowed(e.id));
      select.innerHTML = '<option value="">— Select Election —</option>' +
        elections.map(e => `<option value="${esc(e.id)}">${esc(e.title)}</option>`).join('');
      select.value = previous;
      select.disabled = true;
      document.getElementById('election-hint').textContent = !availableElections.length
        ? 'No elections yet. Create one in Election Management first.'
        : editing ? 'This candidate belongs to this election.'
        : !elections.length ? 'This voter is already a candidate in every election.'
        : document.getElementById('f-member').value
          ? 'Candidates and positions are saved only in this election.'
          : 'Select an eligible voter for this election.';
      return loadCandidatePositions(select.value === previous ? positionId : '');
    }

    async function loadCandidatePositions(positionId = '') {
      const request = ++candidatePositionsRequest;
      const electionId = document.getElementById('f-election').value;
      const select = document.getElementById('f-position');
      availablePositions = [];
      candidatePositionsLoading = !!electionId;
      updatePositionOptions();
      if (!electionId) return;
      select.innerHTML = '<option value="">Loading positions...</option>';
      document.getElementById('position-hint').textContent = 'Loading positions for this election...';
      try {
        const positions = await CoopVote.api('positions&election=' + encodeURIComponent(electionId));
        if (request !== candidatePositionsRequest || document.getElementById('f-election').value !== electionId) return;
        availablePositions = positions.filter(p => String(p.election_id) === electionId);
        candidatePositionsLoading = false;
        updatePositionOptions(positionId);
      } catch (err) {
        if (request !== candidatePositionsRequest) return;
        candidatePositionsLoading = false;
        select.innerHTML = '<option value="">Could not load positions</option>';
        select.disabled = true;
        document.getElementById('position-hint').textContent = 'Select the election again to retry.';
        updateCandidateSaveState();
        CoopVote.toast(err.message || 'Could not load positions.', 'error');
      }
    }

    function candidatePositionLocked() {
      const current = allCandidates.find(c => String(c.id) === document.getElementById('edit-id').value);
      const election = availableElections.find(e => String(e.id) === String(current?.electionId));
      return !!current && (current.positionLocked === true || Number(current.votes) > 0 || election?.canReschedule === false);
    }

    function updatePositionOptions(positionId = '') {
      const electionId = document.getElementById('f-election').value;
      const select = document.getElementById('f-position');
      const positions = electionId ? availablePositions.filter(p => String(p.election_id) === electionId) : [];
      const placeholder = !electionId ? '— Select an Election First —'
        : positions.length ? '— Select Position —' : '— No Positions in This Election —';
      select.innerHTML = `<option value="">${placeholder}</option>` +
        positions.map(p => `<option value="${esc(p.position_id)}">${esc(p.position_name)}</option>`).join('');
      select.value = String(positionId);
      const locked = candidatePositionLocked();
      const current = allCandidates.find(c => String(c.id) === document.getElementById('edit-id').value);
      if (locked) select.value = String(current.positionId);
      select.disabled = locked || positions.length === 0;
      select.hidden = locked;
      const display = document.getElementById('candidate-position-value');
      display.hidden = !locked;
      display.textContent = locked ? current.position : '';
      document.getElementById('position-hint').textContent = locked ? 'Position is locked for this election. You can still edit the profile, platform, and status.'
        : !electionId ? 'Select an election first.'
        : positions.length ? ''
        : 'Add positions to this election in Election Management first.';
      updateCandidateSaveState();
    }

    function updateCandidateSaveState() {
      const electionId = document.getElementById('f-election').value;
      const positionId = document.getElementById('f-position').value;
      const valid = availableElections.some(e => String(e.id) === electionId) && candidateElectionAllowed(electionId) &&
        availableMembers.some(m => String(m.id) === document.getElementById('f-member').value) &&
        availablePositions.some(p => String(p.position_id) === positionId && String(p.election_id) === electionId);
      document.getElementById('save-candidate-btn').disabled = candidateSaving || candidatePhotoReading || candidatePositionsLoading || !valid;
      document.getElementById('f-photo').disabled = candidateSaving;
    }

    function initCandidates(data, elections = candidateFilterElections, selectedId = document.getElementById('election-filter').value) {
      allCandidates = data;
      candidateFilterElections = elections;
      const select = document.getElementById('election-filter');
      const previous = selectedId;
      select.disabled = !elections.length;
      select.innerHTML = (elections.length ? '' : '<option value="">No elections available</option>') + elections.map(e =>
        `<option value="${esc(e.id)}">${esc(e.title)}</option>`).join('');
      select.value = elections.some(e => String(e.id) === previous) ? previous : '';
      updateCandidateFilters();
    }

    function candidateYearSection(candidate) {
      return [candidate.year, candidate.section ? 'Section ' + candidate.section : ''].filter(Boolean).join(' · ') || 'Year & Section not provided';
    }
    function applyCandidateDisplaySettings(settings) {
      showCandidateYearSection = settings.showYearSection === true;
      document.body.classList.toggle('hide-candidate-academics', !showCandidateYearSection);
      // Hidden <col> elements can still reserve width in the native table algorithm.
      const widths = showCandidateYearSection ? [5,29,17,15,7,12,15] : [5,34,22,9,13,17];
      const columns = document.querySelector('.cv-candidates colgroup');
      const markup = widths.map(width => `<col style="width:${width}%">`).join('');
      if (columns.innerHTML !== markup) columns.innerHTML = markup;
      document.querySelectorAll('#cand-tbody td[colspan]').forEach(cell => cell.colSpan = widths.length);
      const fields = document.getElementById('candidate-year-section-fields');
      fields.hidden = !showCandidateYearSection;
      fields.disabled = !showCandidateYearSection;
      ['f-year', 'f-section'].forEach(id => document.getElementById(id).required = showCandidateYearSection);
    }
    function candidatesInSelectedElection() {
      const id = document.getElementById('election-filter').value;
      return id ? allCandidates.filter(c => String(c.electionId) === id) : [];
    }

    function selectedCandidateElection() {
      return candidateFilterElections.find(e => String(e.id) === document.getElementById('election-filter').value);
    }

    function selectedCandidatePositions() {
      return selectedCandidateElection()?.positionList || [];
    }

    function changeCandidateElection() {
      ++candidateListRequest;
      ++candidateOptionsRequest;
      ++candidatePositionsRequest;
      closeModal('cand-modal');
      closeModal('confirm-modal');
      closeModal('view-modal');
      deleteTarget = null;
      allCandidates = [];
      document.getElementById('pos-filter').value = '';
      const link = new URL(location.href);
      const id = document.getElementById('election-filter').value;
      if (id) link.searchParams.set('election', id); else link.searchParams.delete('election');
      history.replaceState(null, '', link.href);
      return loadCandidates();
    }

    function updateCandidateFilters() {
      const select = document.getElementById('pos-filter');
      const previous = select.value;
      const positions = selectedCandidatePositions();
      select.innerHTML = '<option value="">All Positions</option>' + positions.map(p =>
        `<option value="${Number(p.id)}">${esc(p.name)}</option>`).join('');
      select.value = positions.some(p => String(p.id) === previous) ? previous : '';
      applyFilters();
    }

    /* ─────────────────────────────────────────
       SUMMARY
    ───────────────────────────────────────── */
    function updateSummary(candidates = allCandidates) {
      const set = (id, val) => {
        const el = document.getElementById(id);
        if (!el) return;
        if (val === null) { el.textContent = '—'; el.className = 's-val empty'; return; }
        el.className = 's-val';
        if (typeof val === 'string') { el.textContent = val; return; }
        el.textContent = String(val);
      };

      const election = selectedCandidateElection();
      const positions = selectedCandidatePositions();
      const ready = !!election && !candidateListLoading && !candidateListError;
      document.getElementById('candidate-election-title').textContent = election?.title || (candidateListLoading ? 'Loading election…' : 'No elections available');
      document.getElementById('candidate-election-status').textContent = election ? `${election.status} · ${positions.length} position${positions.length === 1 ? '' : 's'}` : '';
      document.getElementById('candidate-retry').hidden = !candidateListError;
      const add = document.getElementById('add-candidate-btn');
      add.disabled = !ready || !election || !positions.length;
      add.title = !election ? 'Select an election first.' : candidateListLoading ? 'Loading candidates…' : candidateListError ? 'Retry loading before adding a candidate.' : !positions.length ? 'Add positions in Election Management first.' : 'Add a candidate to this election';
      const hint = document.getElementById('candidate-setup-hint');
      hint.textContent = candidateListLoading ? 'Loading election setup…' : candidateListError || (!election ? 'Create an election in Election Management to start adding candidates.' : !positions.length ? 'Add positions in Election Management before adding candidates.' : 'Use the list or grouped view to review candidates. Add Candidate nominates a voter to this election.');
      if (ready && election && !positions.length) {
        const link = document.createElement('a'); link.href = 'election-management.php?election=' + Number(election.id);
        link.textContent = ' Open Election Management'; hint.append(link);
      }
      if (!ready) {
        ['sc-total', 'sc-positions', 'sc-active', 'sc-empty'].forEach(id => set(id, null));
        return;
      }
      const occupied = new Set(candidates.map(c => Number(c.positionId)));
      const filled = positions.filter(p => occupied.has(Number(p.id))).length;
      set('sc-total', candidates.length);
      set('sc-positions', filled);
      set('sc-active', candidates.filter(c => c.status === 'Active').length);
      set('sc-empty', positions.length - filled);
    }

    /* ─────────────────────────────────────────
       FILTER
    ───────────────────────────────────────── */
    function applyFilters() {
      const q = document.getElementById('search-input').value.trim().toLowerCase();
      const p = document.getElementById('pos-filter').value;
      const st = document.getElementById('status-filter').value;
      const scoped = candidatesInSelectedElection();
      filtered = scoped.filter(c => {
        const hay = [c.first, c.last, c.position, c.election, c.memberNo].join(' ').toLowerCase();
        return (!q || hay.includes(q)) && (!p || String(c.positionId) === p) && (!st || c.status === st);
      });
      currentPage = 1;
      updateSummary(scoped);
      renderView();
    }

    /* ─────────────────────────────────────────
       VIEW TOGGLE
    ───────────────────────────────────────── */
    function setView(v) {
      currentView = v;
      document.getElementById('grid-view').style.display = v === 'grid' ? 'grid' : 'none';
      document.getElementById('list-view').style.display = v === 'list' ? 'flex' : 'none';
      document.getElementById('grid-btn').classList.toggle('active', v === 'grid');
      document.getElementById('list-btn').classList.toggle('active', v === 'list');
      ['grid', 'list'].forEach(view => document.getElementById(view + '-btn').setAttribute('aria-pressed', String(v === view)));
      renderView();
    }

    function renderView() {
      if (candidateListLoading || candidateListError) {
        const message = candidateListLoading ? 'Loading candidates…' : 'Candidate setup could not be loaded. Use Retry loading above.';
        document.getElementById('grid-view').innerHTML = `<div class="position-empty">${message}</div>`;
        document.getElementById('cand-tbody').innerHTML = `<tr><td colspan="${showCandidateYearSection ? 7 : 6}">${message}</td></tr>`;
        document.getElementById('pag-info').textContent = '';
        document.getElementById('pag-btns').innerHTML = '';
        return;
      }
      if (currentView === 'grid') renderGrid();
      else renderTable();
    }

    /* ─────────────────────────────────────────
       GRID RENDER
    ───────────────────────────────────────── */
    function renderGrid() {
      const wrap = document.getElementById('grid-view');
      const election = selectedCandidateElection();
      const positionId = document.getElementById('pos-filter').value;
      const query = document.getElementById('search-input').value.trim().toLowerCase();
      const status = document.getElementById('status-filter').value;
      const positions = selectedCandidatePositions().filter(p => (!positionId || String(p.id) === positionId) &&
        (!query || p.name.toLowerCase().includes(query) || filtered.some(c => Number(c.positionId) === Number(p.id))));
      if (!positions.length) {
        wrap.innerHTML = `<div class="position-empty">${selectedCandidatePositions().length ? 'No positions match your filters. Clear the search or choose All Positions.' : 'No positions yet. Add positions in Election Management to start nominating candidates.'}</div>`;
        return;
      }
      wrap.innerHTML = positions.map(p => {
        const members = filtered.filter(c => Number(c.positionId) === Number(p.id));
        const all = candidatesInSelectedElection().filter(c => Number(c.positionId) === Number(p.id));
        const active = all.filter(c => c.status === 'Active').length;
        return `<section class="position-group" data-position-id="${Number(p.id)}" aria-labelledby="position-title-${Number(p.id)}">
          <div class="position-head"><div><h3 id="position-title-${Number(p.id)}">${esc(p.name)}</h3>
          <p>${all.length} candidate${all.length === 1 ? '' : 's'} · ${active} active${all.length && !active ? ' · No active candidates' : ''}${query || status ? ` · ${members.length} shown` : ''}</p></div>
          </div>
          ${members.length ? `<div class="position-candidates">${members.map(renderCandidateCard).join('')}</div>` : `<div class="position-empty"><p>${all.length ? 'No candidates match the current search and status filters.' : `No candidates yet for ${esc(p.name)}. Use Add Candidate in the toolbar to nominate a voter.`}</p></div>`}
        </section>`;
      }).join('');
    }

    function renderCandidateCard(c) {
        const { bg, fg } = avatarColor(c.id || 0);
        const pColor = posColor(c.position);
        const avatarEl = c.photoUrl
          ? `<button type="button" class="card-avatar" data-candidate-photo aria-label="View larger photo of ${esc(c.first)} ${esc(c.last)}" title="View larger photo" style="background:${bg}"><img src="${esc(c.photoUrl)}" alt="${esc(c.first)} ${esc(c.last)}"/></button>`
          : `<div class="card-avatar" style="background:${bg};color:${fg}">${initials(c.first, c.last)}</div>`;
        return `<div class="cand-card">
      <div class="card-banner" style="background:linear-gradient(135deg,${bg},${bg}bb)">${avatarEl}</div>
      <div class="card-info">
        <div class="card-name">${esc(c.first)} ${esc(c.last)}</div>
        <div>${statusBadge(c.status)}</div>
        <div class="card-position" style="background:${pColor}22;color:${pColor};border:1px solid ${pColor}44">${esc(c.position || '—')}</div>
        ${showCandidateYearSection ? `<div class="candidate-academic-label">${esc(candidateYearSection(c))}</div>` : ''}
        <div class="card-desc">${c.desc ? esc(c.desc) : '<span style="opacity:.4">No description provided.</span>'}</div>
        <div class="card-meta">
          <div class="meta-item"><div class="meta-val" style="color:var(--gold)">${c.votes ?? '—'}</div><div class="meta-label">Votes</div></div>

        </div>
      </div>
      <div class="card-actions">
        <button class="card-btn view-btn" onclick="viewCandidate(${c.id})">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View
        </button>
        <button class="card-btn edit-btn" onclick="openEdit(${c.id})">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit
        </button>
        <button class="card-btn del-btn" onclick="askDelete(${c.id})">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>Remove
        </button>
      </div>
    </div>`;
    }

    /* ─────────────────────────────────────────
       TABLE RENDER
    ───────────────────────────────────────── */
    function renderTable() {
      const tbody = document.getElementById('cand-tbody');
      const pagInfo = document.getElementById('pag-info');
      const pagBtns = document.getElementById('pag-btns');

      if (filtered.length === 0) {
        currentPage = 1;
        const isFiltering = document.getElementById('election-filter').value || document.getElementById('search-input').value.trim() ||
          document.getElementById('pos-filter').value ||
          document.getElementById('status-filter').value;
        tbody.innerHTML = `<tr><td colspan="${showCandidateYearSection ? 7 : 6}">
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <p>${isFiltering ? 'No candidates match these filters. Try another election, position, or search.' : 'No candidates added yet. Choose an election, then use Add Candidate.'}</p>
      </div>
    </td></tr>`;
        pagInfo.textContent = '0 records'; pagBtns.innerHTML = ''; return;
      }

      const totalPages = Math.ceil(filtered.length / PAGE_SIZE);
      currentPage = Math.max(1, Math.min(currentPage, totalPages));
      const start = (currentPage - 1) * PAGE_SIZE;
      const slice = filtered.slice(start, start + PAGE_SIZE);

      tbody.innerHTML = slice.map((c, index) => {
        const { bg, fg } = avatarColor(c.id || 0);
        const pColor = posColor(c.position);
        const avatarEl = c.photoUrl
          ? `<button type="button" class="t-avatar" data-candidate-photo aria-label="View larger photo of ${esc(c.first)} ${esc(c.last)}" title="View larger photo" style="background:${bg}"><img src="${esc(c.photoUrl)}" alt="${esc(c.first)} ${esc(c.last)}"/></button>`
          : `<div class="t-avatar" style="background:${bg};color:${fg}">${initials(c.first, c.last)}</div>`;
        return `<tr>
      <td class="row-number">${start + index + 1}</td>
      <td><div class="cand-cell">${avatarEl}<div>
        <div class="t-name">${esc(c.first)} ${esc(c.last)}</div>

      </div></div></td>
      <td><span class="badge" style="background:${pColor}22;color:${pColor};border:none">${esc(c.position || '—')}</span></td>
      <td class="candidate-academic-column">${showCandidateYearSection ? esc(candidateYearSection(c)) : ''}</td>
      <td><span style="font-family:'Playfair Display',serif;font-size:1rem;font-weight:700;color:var(--gold)">${c.votes ?? '—'}</span></td>
      <td>${statusBadge(c.status)}</td>
      <td><div class="action-btns">
        <button class="icon-btn view" title="View" onclick="viewCandidate(${c.id})">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
        <button class="icon-btn edit" title="Edit" onclick="openEdit(${c.id})">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </button>
        <button class="icon-btn del" title="Remove" onclick="askDelete(${c.id})">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        </button>
      </div></td>
    </tr>`;
      }).join('');

      const end = Math.min(start + PAGE_SIZE, filtered.length);
      pagInfo.textContent = `Showing ${start + 1}–${end} of ${filtered.length} candidate${filtered.length !== 1 ? 's' : ''}`;

      CoopVote.renderPagination(pagBtns, currentPage, totalPages, goPage);
    }

    function goPage(p) {
      const totalPages = Math.ceil(filtered.length / PAGE_SIZE);
      if (p < 1 || p > totalPages) return;
      currentPage = p; renderTable();
      document.querySelector('#list-view .table-scroll').scrollTop = 0;
    }

    /* ─────────────────────────────────────────
       VIEW CANDIDATE PROFILE
    ───────────────────────────────────────── */
    function viewCandidate(id) {
      const c = candidatesInSelectedElection().find(x => x.id === id);
      if (!c) return;
      const { bg, fg } = avatarColor(c.id || 0);
      const name = `${c.first || ''} ${c.last || ''}`.trim();
      const avHTML = c.photoUrl
        ? `<button type="button" class="candidate-profile-photo" data-candidate-photo aria-label="View larger photo of ${esc(name)}" title="View larger photo"><img src="${esc(c.photoUrl)}" alt="${esc(name)}"/><span class="candidate-photo-expand" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></svg></span></button>`
        : `<div class="candidate-profile-photo candidate-profile-initials" style="background:${bg};color:${fg}" aria-label="No profile photo">${esc(initials(c.first, c.last))}</div>`;
      const facts = [
        ['Member ID', c.memberNo || 'Not provided'],
        ...(showCandidateYearSection ? [['Year & Section', candidateYearSection(c) || 'Not provided']] : []),
        ['Votes received', c.votes == null ? 'Not available' : Number(c.votes).toLocaleString()],
      ];
      document.getElementById('view-body').innerHTML = `
        <div class="candidate-profile-header">
          ${avHTML}
          <div class="candidate-profile-identity"><h2>${esc(name)}</h2><p>${esc(c.position || 'No position assigned')}</p><div class="candidate-profile-status">${statusBadge(c.status)}</div></div>
        </div>
        <section class="candidate-profile-election" aria-labelledby="candidate-election-label"><h3 id="candidate-election-label">Election</h3><p>${esc(c.election || 'Not available')}</p></section>
        <dl class="candidate-profile-facts">
          ${facts.map(([label,value])=>`<div><dt>${esc(label)}</dt><dd>${esc(String(value))}</dd></div>`).join('')}
        </dl>
        <section class="candidate-profile-about" aria-labelledby="candidate-about-label"><h3 id="candidate-about-label">About the candidate</h3><p${c.desc ? '' : ' class="candidate-profile-empty"'}>${esc(c.desc || 'No description has been added for this candidate.')}</p></section>`;
      document.getElementById('view-body').scrollTop = 0;
      document.getElementById('view-edit-btn').onclick = () => { closeModal('view-modal'); openEdit(id); };
      openModal('view-modal');
    }

    /* ─────────────────────────────────────────
       ADD / EDIT CANDIDATE
    ───────────────────────────────────────── */
    function clearForm() {
      resetCandidatePhoto();
      ++candidateOptionsRequest;
      ++candidatePositionsRequest;
      candidatePositionsLoading = false;
      availablePositions = [];
      ['f-first', 'f-last', 'f-section', 'f-year', 'f-desc'].forEach(id => { const e = document.getElementById(id); if (e) e.value = ''; });
      document.getElementById('f-election').value = '';
      updatePositionOptions();
      document.getElementById('f-status').value = 'Active';
      document.getElementById('edit-id').value = '';
      document.getElementById('f-member').value = '';
      document.getElementById('f-member-search').value = '';
      updateCandidateSaveState();
      document.getElementById('photo-preview').innerHTML =
        `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24" style="color:var(--muted)"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`;
    }

    function openAdd(positionId = '') {
      if (!document.getElementById('election-filter').value) { showToast('Select an election before adding a candidate.', 'warn'); document.getElementById('election-filter').focus(); return; }
      if (candidateListLoading || candidateListError || !selectedCandidatePositions().length) return;
      if (positionId && !selectedCandidatePositions().some(p => Number(p.id) === Number(positionId))) return;
      document.getElementById('cand-modal').classList.remove('is-editing');
      document.getElementById('modal-title').textContent = 'Add New Candidate';
      clearForm();
      openModal('cand-modal');
      loadCandidateOptions(document.getElementById('election-filter').value, String(positionId));
    }

    function openEdit(id) {
      const c = candidatesInSelectedElection().find(x => x.id === id);
      if (!c) return;
      resetCandidatePhoto();
      document.getElementById('cand-modal').classList.add('is-editing');
      document.getElementById('modal-title').textContent = 'Edit Candidate';
      document.getElementById('edit-id').value = c.id;
      ['f-first', 'f-last'].forEach(id => {
        document.getElementById(id).readOnly = true;
        document.getElementById(id).title = 'Edit the member name in Voter Management.';
      });
      document.getElementById('f-first').value = c.first || '';
      document.getElementById('f-last').value = c.last || '';
      ['section', 'year'].forEach(field => { document.getElementById('f-' + field).value = c[field] || ''; });
      document.getElementById('f-desc').value = c.desc || '';
      document.getElementById('f-status').value = c.status || 'Active';
      const preview = document.getElementById('photo-preview');
      preview.innerHTML = c.photoUrl
        ? `<img src="${esc(c.photoUrl)}" style="width:100%;height:100%;object-fit:cover;border-radius:50%"/>`
        : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24" style="color:var(--muted)"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`;
      openModal('cand-modal');
      loadCandidateOptions(c.electionId, c.positionId, c.memberId);
    }

    async function saveCandidate() {
      if (candidateSaving || candidatePhotoReading || candidatePositionsLoading) return;
      if (!CoopVoteValidation.validate('#cand-modal')) return;
      const memberId = document.getElementById('f-member').value;
      const electionId = document.getElementById('f-election').value;
      const positionId = document.getElementById('f-position').value;
      if (electionId !== document.getElementById('election-filter').value) { showToast('The selected election changed. Reopen the candidate form.', 'warn'); return; }
      if (!electionId) { showToast('Please select an election first.', 'warn'); document.getElementById('f-election').focus(); return; }
      if (!availableMembers.some(m => String(m.id) === memberId)) {
        showToast('Please select an existing voter.', 'warn'); document.getElementById('f-member').focus(); return;
      }
      if (!candidateElectionAllowed(electionId)) {
        showToast('This voter is already a candidate in that election. Select another election.', 'warn');
        updateCandidateElectionOptions();
        return;
      }
      if (!positionId) { showToast('Please select a position.', 'warn'); return; }
      if (!availablePositions.some(p => String(p.position_id) === positionId && String(p.election_id) === electionId)) {
        showToast('Please select a position from this election.', 'warn'); return;
      }
      const editId = document.getElementById('edit-id').value;
      const val = id => document.getElementById(id)?.value.trim() || '';

      candidateSaving = true;
      updateCandidateSaveState();
      return CoopVote.api((editId ? `candidates/${editId}` : 'candidates') + '&election=' + encodeURIComponent(electionId), {
        method: editId ? 'PUT' : 'POST',
        body: {
          memberId, electionId, positionId,
          ...(showCandidateYearSection ? {section: val('f-section'), year: val('f-year')} : {}),
          desc:     val('f-desc'),
          platform: val('f-platform') || val('f-desc'),
          status:   val('f-status') || 'Active',
          ...(candidatePhotoChange === undefined ? {} : {photoData: candidatePhotoChange})
        }
      })
        .then(() => {
          closeModal('cand-modal');
          showToast('Candidate saved successfully.', 'success');
          loadCandidates();
        })
        .catch(err => {
          if (err.code === 'POSITION_LOCKED') {
            const current = allCandidates.find(c => String(c.id) === editId);
            if (current) {
              current.positionLocked = true;
              current.positionId = err.positionId;
              current.position = err.position;
              updatePositionOptions(current.positionId);
            }
          }
          showToast(err.message || 'Could not save this candidate.', 'error');
        })
        .finally(() => { candidateSaving = false; updateCandidateSaveState(); });
    }

    /* ─────────────────────────────────────────
       DELETE CANDIDATE
    ───────────────────────────────────────── */
    function askDelete(id) {
      const candidate = candidatesInSelectedElection().find(c => c.id === id);
      if (!candidate || candidateListLoading || candidateListError) return;
      deleteTarget = id;
      document.getElementById('candidate-remove-title').textContent = `Remove ${candidate.first} ${candidate.last}?`;
      document.getElementById('candidate-remove-message').textContent =
        `This nomination is for ${candidate.position} in ${candidate.election}. If voting records exist, the candidate will be marked inactive and those records will be preserved. Otherwise, the nomination will be deleted. The voter account will remain.`;
      openModal('confirm-modal');
    }

    function confirmDelete() {
      const id = deleteTarget;
      const candidate = candidatesInSelectedElection().find(c => c.id === id);
      if (!id || !candidate) return;
      deleteTarget = null;

      CoopVote.api(`candidates/${id}&election=${encodeURIComponent(candidate.electionId)}`, { method: 'DELETE' })
        .then(res => {
          closeModal('confirm-modal');
          /* A candidate with votes is marked withdrawn so the tally survives. */
          showToast(res.message || 'Candidate removed.', res.deactivated ? 'warn' : 'success');
          loadCandidates();
        })
        .catch(err => {
          closeModal('confirm-modal');
          showToast(err.message || 'Could not remove this candidate.', 'error');
        });
    }

    /* ─────────────────────────────────────────
       EXPORT
    ───────────────────────────────────────── */
    async function exportCandidates() {
      if (candidatesInSelectedElection().length === 0) { showToast('No candidate data to export.', 'warn'); return; }
      const id = document.getElementById('election-filter').value;
      if (await CoopVote.download('candidates/export&election=' + encodeURIComponent(id))) showToast('Your CSV download is starting.', 'success');
    }

    /* ─────────────────────────────────────────
       MODAL HELPERS
    ───────────────────────────────────────── */
    function openModal(id) { document.getElementById(id).classList.add('show'); }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); }

    /* ─────────────────────────────────────────
       TOAST
    ───────────────────────────────────────── */
    function showToast(msg, type = 'success') {
      const wrap = document.getElementById('toast-wrap');
      const t = document.createElement('div');
      t.className = `toast ${type}`;
      if (type === 'info' && /^\d+ unread notifications?\./.test(msg)) t.classList.add('toast-notification');
      t.innerHTML = `<div class="toast-dot"></div><span>${esc(msg)}</span>`;
      wrap.appendChild(t);
      CoopVote.presentToast(t, 3400);
    }

    /* ─────────────────────────────────────────
       INIT
    ───────────────────────────────────────── */
    window.addEventListener('DOMContentLoaded', () => {
      CoopVote.guard('admin').then(loadCandidates);

      /*
       * When your backend is ready, replace loadCandidates() with:
       *
       * fetch('/api/candidates')
       *   .then(r => r.json())
       *   .then(data => initCandidates(data))
       *   .catch(() => { initCandidates([]); showToast('Failed to load candidates.','error'); });
       */
    });
  </script>
</body>

</html>
