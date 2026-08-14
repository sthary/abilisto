<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>GreenLoop — Scrap Value Scanner</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- PATCH 1: Leaflet CSS and JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV/XN/WPeE=" crossorigin=""></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
/* ═══ RESET ═══ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
button { font-family: inherit; cursor: pointer; border: none; }
input, select, textarea { font-family: inherit; }

/* ═══ DESIGN TOKENS ═══ */
:root {
  /* Core palette — deep forest industrial */
  --c-void:     #080c09;
  --c-base:     #0d1410;
  --c-raise:    #131b15;
  --c-lift:     #182019;
  --c-float:    #1f2b21;
  --c-edge:     #243027;

  /* Accent — neon moss */
  --c-green:    #2dff7a;
  --c-green-2:  #1cd464;
  --c-green-3:  #0f8c41;
  --c-green-4:  #083d1d;
  --c-green-5:  #041f0f;

  /* Signal */
  --c-gold:     #ffcc2d;
  --c-gold-2:   #c49a00;
  --c-gold-3:   #5c4800;
  --c-red:      #ff4b4b;
  --c-red-2:    #8c0f0f;

  /* Text */
  --c-text:     #d8edd9;
  --c-text-2:   #7a9c7e;
  --c-text-3:   #3c5240;

  /* Borders */
  --c-border:   #1a2a1c;
  --c-border-2: #253529;
  --c-border-3: #304538;

  /* Type */
  --f-head: 'Outfit', sans-serif;
  --f-mono: 'Space Mono', monospace;
  --f-body: 'Outfit', sans-serif;

  /* Radii */
  --r-sm:  8px;
  --r-md:  14px;
  --r-lg:  20px;
  --r-xl:  28px;
  --r-max: 999px;

  /* Transitions */
  --t-fast: 120ms ease;
  --t-mid:  240ms ease;
  --t-slow: 400ms cubic-bezier(0.34, 1.56, 0.64, 1);
}

/* ═══ GLOBAL ═══ */
html { scroll-behavior: smooth; }

body {
  font-family: var(--f-body);
  background: var(--c-void);
  color: var(--c-text);
  min-height: 100vh;
  overflow-x: hidden;
  -webkit-font-smoothing: antialiased;
}

/* Atmospheric background */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background:
    radial-gradient(ellipse 60% 40% at 20% 10%, rgba(45,255,122,0.04) 0%, transparent 70%),
    radial-gradient(ellipse 40% 60% at 80% 80%, rgba(45,255,122,0.03) 0%, transparent 70%),
    repeating-linear-gradient(0deg, transparent, transparent 60px, rgba(45,255,122,0.012) 60px, rgba(45,255,122,0.012) 61px),
    repeating-linear-gradient(90deg, transparent, transparent 60px, rgba(45,255,122,0.008) 60px, rgba(45,255,122,0.008) 61px);
  pointer-events: none;
  z-index: 0;
}

/* ═══ LAYOUT ═══ */
.app-shell {
  max-width: 480px;
  margin: 0 auto;
  min-height: 100vh;
  position: relative;
  z-index: 1;
  border-left: 1px solid var(--c-border);
  border-right: 1px solid var(--c-border);
}

/* ═══ NAV ═══ */
.nav {
  position: sticky; top: 0; z-index: 200;
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px;
  background: rgba(8,12,9,0.88);
  backdrop-filter: blur(20px) saturate(1.6);
  border-bottom: 1px solid var(--c-border-2);
}

.nav-logo {
  display: flex; align-items: center; gap: 8px; flex: 1;
}

.nav-icon {
  width: 30px; height: 30px;
  background: var(--c-green-4);
  border: 1px solid var(--c-green-3);
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
}

.nav-wordmark {
  font-family: var(--f-head);
  font-weight: 800;
  font-size: 16px;
  letter-spacing: -0.5px;
  color: var(--c-text);
}
.nav-wordmark em {
  font-style: normal;
  color: var(--c-green);
}

.wallet-chip {
  display: flex; align-items: center; gap: 5px;
  background: var(--c-green-4);
  border: 1px solid var(--c-green-3);
  border-radius: var(--r-max);
  padding: 5px 11px;
  font-family: var(--f-mono);
  font-size: 11px;
  color: var(--c-green);
  transition: var(--t-mid);
}

.wallet-chip.bump {
  animation: coin-bump 0.4s var(--t-slow);
}
@keyframes coin-bump {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.12); background: rgba(45,255,122,0.2); }
}

/* ═══ API STATUS BAR ═══ */
.api-zone {
  padding: 8px 16px;
  border-bottom: 1px solid var(--c-border);
  background: var(--c-base);
}

.api-hint {
  font-size: 10px;
  color: var(--c-text-3);
  display: flex; align-items: center; justify-content: center; gap: 6px;
}

/* Key indicator dot */
.key-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--c-green);
  box-shadow: 0 0 6px var(--c-green);
  display: inline-block;
  animation: pulse-dot 2s ease infinite;
}
@keyframes pulse-dot {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

/* ═══ HERO ═══ */
.hero {
  padding: 28px 20px 20px;
}

.hero-tag {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 10px;
  font-family: var(--f-mono);
  font-weight: 700;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--c-green-2);
  border: 1px solid var(--c-green-4);
  border-radius: var(--r-max);
  padding: 4px 12px;
  margin-bottom: 16px;
}
.hero-tag::before {
  content: '';
  width: 5px; height: 5px; border-radius: 50%;
  background: var(--c-green);
  animation: blink 2s step-end infinite;
}
@keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }

.hero-title {
  font-family: var(--f-head);
  font-weight: 900;
  font-size: 34px;
  line-height: 1.05;
  letter-spacing: -1px;
  margin-bottom: 12px;
}
.hero-title .hl {
  color: var(--c-green);
  position: relative;
}
.hero-title .hl::after {
  content: '';
  position: absolute;
  bottom: 2px; left: 0; right: 0;
  height: 2px;
  background: var(--c-green);
  opacity: 0.3;
  border-radius: 2px;
}

.hero-body {
  font-size: 13px;
  color: var(--c-text-2);
  line-height: 1.7;
  max-width: 280px;
}

/* ═══ PROGRESS TRACK ═══ */
.progress-track {
  margin: 0 16px 20px;
  display: flex;
  gap: 3px;
}
.pt-seg {
  height: 3px;
  flex: 1;
  border-radius: 2px;
  background: var(--c-edge);
  transition: background var(--t-mid), box-shadow var(--t-mid);
}
.pt-seg.done   { background: var(--c-green-3); }
.pt-seg.active { background: var(--c-green); box-shadow: 0 0 8px var(--c-green); }

/* ═══ SECTION ═══ */
.section { padding: 0 16px; margin-bottom: 24px; }

.section-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-family: var(--f-mono);
  font-size: 9px;
  letter-spacing: 2.5px;
  text-transform: uppercase;
  color: var(--c-text-3);
  font-weight: 700;
  margin-bottom: 12px;
}
.section-label::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--c-border-2);
}

/* ═══ INPUT PANEL ═══ */
.input-panel {
  background: var(--c-raise);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-lg);
  overflow: hidden;
}

.tab-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  background: var(--c-base);
  border-bottom: 1px solid var(--c-border);
}
.tab-btn {
  padding: 11px 6px;
  font-size: 11px;
  font-weight: 700;
  color: var(--c-text-3);
  background: none;
  text-align: center;
  transition: var(--t-fast);
  border-bottom: 2px solid transparent;
  letter-spacing: 0.3px;
}
.tab-btn:hover { color: var(--c-text-2); }
.tab-btn.on {
  color: var(--c-green);
  border-bottom-color: var(--c-green);
  background: rgba(45,255,122,0.04);
}

.tab-pane { display: none; }
.tab-pane.on { display: block; }

/* DROP ZONE */
.drop-zone {
  padding: 40px 20px;
  text-align: center;
  cursor: pointer;
  transition: var(--t-mid);
  background: repeating-linear-gradient(
    -45deg,
    transparent, transparent 12px,
    rgba(45,255,122,0.015) 12px, rgba(45,255,122,0.015) 13px
  );
}
.drop-zone:hover { background: rgba(45,255,122,0.05); }
.drop-zone.over  { background: rgba(45,255,122,0.08); border: 2px dashed var(--c-green-3); }

.dz-icon {
  width: 64px; height: 64px;
  margin: 0 auto 16px;
  background: var(--c-green-5);
  border: 1px solid var(--c-green-4);
  border-radius: 18px;
  display: flex; align-items: center; justify-content: center;
  font-size: 28px;
  animation: levitate 4s ease-in-out infinite;
}
@keyframes levitate {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-8px); }
}

.dz-title {
  font-family: var(--f-head);
  font-weight: 700;
  font-size: 15px;
  margin-bottom: 6px;
  color: var(--c-text);
}
.dz-sub { font-size: 12px; color: var(--c-text-2); margin-bottom: 12px; }

.format-tags { display: flex; justify-content: center; gap: 5px; }
.fmt-tag {
  font-family: var(--f-mono);
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 1px;
  text-transform: uppercase;
  background: var(--c-edge);
  border: 1px solid var(--c-border-3);
  color: var(--c-text-3);
  border-radius: 4px;
  padding: 3px 8px;
}

/* PREVIEW */
#preview-wrap { display: none; padding: 14px; }
#img-preview {
  width: 100%;
  max-height: 200px;
  object-fit: cover;
  border-radius: var(--r-md);
  border: 1px solid var(--c-border-3);
  display: block;
}
.preview-controls { display: flex; gap: 8px; margin-top: 10px; }
.hint-input {
  flex: 1;
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-sm);
  padding: 9px 12px;
  font-size: 13px;
  color: var(--c-text);
  outline: none;
  transition: border-color var(--t-fast);
}
.hint-input:focus { border-color: var(--c-green); }
.hint-input::placeholder { color: var(--c-text-3); }
.btn-clear {
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  color: var(--c-text-2);
  border-radius: var(--r-sm);
  padding: 9px 12px;
  font-size: 14px;
  transition: var(--t-fast);
}
.btn-clear:hover { color: var(--c-red); border-color: var(--c-red); background: rgba(255,75,75,0.08); }

/* TEXT INPUT */
.text-input-wrap { padding: 14px; }
.item-input {
  width: 100%;
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-md);
  padding: 14px 16px;
  font-size: 14px;
  color: var(--c-text);
  outline: none;
  transition: border-color var(--t-fast);
  line-height: 1.5;
}
.item-input:focus { border-color: var(--c-green); }
.item-input::placeholder { color: var(--c-text-3); }

/* SCAN BTN */
.btn-scan {
  width: calc(100% - 28px);
  margin: 0 14px 14px;
  padding: 14px;
  border-radius: var(--r-md);
  background: var(--c-green);
  color: var(--c-void);
  font-family: var(--f-head);
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: var(--t-slow);
  position: relative;
  overflow: hidden;
}
.btn-scan::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, transparent 60%);
}
.btn-scan:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(45,255,122,0.3); }
.btn-scan:active:not(:disabled) { transform: translateY(0); }
.btn-scan:disabled { opacity: 0.35; cursor: not-allowed; }

/* ═══ CATALOG ═══ */
.catalog-wrap { max-height: 340px; overflow-y: auto; }
.catalog-wrap::-webkit-scrollbar { width: 4px; }
.catalog-wrap::-webkit-scrollbar-thumb { background: var(--c-border-3); border-radius: 2px; }

.cat-group-hdr {
  padding: 8px 14px;
  font-family: var(--f-mono);
  font-size: 9px;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--c-text-3);
  background: var(--c-base);
  border-bottom: 1px solid var(--c-border);
  font-weight: 700;
}
.cat-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
}
.cat-card {
  padding: 13px 14px;
  cursor: pointer;
  border-right: 1px solid var(--c-border);
  border-bottom: 1px solid var(--c-border);
  transition: background var(--t-fast);
  position: relative;
}
.cat-card:nth-child(even) { border-right: none; }
.cat-card:hover { background: var(--c-lift); }
.cat-card.sel { background: rgba(45,255,122,0.06); }

.cat-check {
  position: absolute; top: 8px; right: 8px;
  width: 18px; height: 18px; border-radius: 50%;
  background: var(--c-green); color: var(--c-void);
  font-size: 10px; font-weight: 900;
  display: none; align-items: center; justify-content: center;
}
.cat-card.sel .cat-check { display: flex; }
.cat-name { font-size: 12px; font-weight: 600; color: var(--c-text); margin-bottom: 4px; line-height: 1.3; }
.cat-coins { font-family: var(--f-mono); font-size: 10px; font-weight: 700; color: var(--c-gold); }

/* ═══ ANALYZING STATE ═══ */
.analyzing-wrap {
  display: none;
  padding: 40px 20px;
  text-align: center;
}
.analyzing-wrap.on { display: block; }
.scan-animation {
  width: 88px; height: 88px;
  margin: 0 auto 20px;
  position: relative;
  display: flex; align-items: center; justify-content: center;
}
.ring {
  position: absolute;
  border-radius: 50%;
  border: 1.5px solid var(--c-green);
  animation: pulse-ring 2s cubic-bezier(0.2, 0, 0.8, 1) infinite;
}
.ring-1 { width: 88px; height: 88px; }
.ring-2 { width: 64px; height: 64px; animation-delay: 0.4s; opacity: 0.7; }
.ring-3 { width: 44px; height: 44px; animation-delay: 0.8s; opacity: 0.4; }
@keyframes pulse-ring {
  0% { transform: scale(0.6); opacity: 1; }
  100% { transform: scale(1.3); opacity: 0; }
}
.scan-core {
  width: 34px; height: 34px;
  background: linear-gradient(135deg, var(--c-green-4), var(--c-green-5));
  border: 1px solid var(--c-green-3);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  position: relative;
  z-index: 2;
  animation: core-spin 6s linear infinite;
}
@keyframes core-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

.scan-title {
  font-family: var(--f-head);
  font-size: 16px;
  font-weight: 800;
  color: var(--c-green);
  margin-bottom: 4px;
}
.scan-sub { font-size: 11px; color: var(--c-text-3); font-family: var(--f-mono); margin-bottom: 20px; }

.scan-steps { display: flex; flex-direction: column; gap: 8px; text-align: left; max-width: 220px; margin: 0 auto; }
.s-step {
  display: flex; align-items: center; gap: 10px;
  font-size: 12px; color: var(--c-text-3);
  transition: color var(--t-mid);
}
.s-step .sdot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--c-edge); border: 1px solid var(--c-border-2);
  flex-shrink: 0;
  transition: all var(--t-mid);
}
.s-step.active { color: var(--c-text-2); }
.s-step.active .sdot { background: var(--c-gold); box-shadow: 0 0 8px var(--c-gold); animation: sdot-pulse 1s ease infinite; }
@keyframes sdot-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
.s-step.done { color: var(--c-green-2); }
.s-step.done .sdot { background: var(--c-green); box-shadow: 0 0 6px var(--c-green); }

/* ═══ RESULT PANEL ═══ */
.result-header {
  background: var(--c-raise);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-lg) var(--r-lg) 0 0;
  padding: 18px;
  display: flex; align-items: center; gap: 14px;
  border-bottom: 1px solid var(--c-border);
}
.res-icon-box {
  width: 54px; height: 54px;
  background: var(--c-lift);
  border: 1px solid var(--c-border-3);
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 28px;
  flex-shrink: 0;
}
.res-meta { flex: 1; min-width: 0; }
.res-detected-tag {
  font-family: var(--f-mono);
  font-size: 9px; font-weight: 700;
  letter-spacing: 1.5px; text-transform: uppercase;
  color: var(--c-text-3); margin-bottom: 4px;
}
.res-item-name {
  font-family: var(--f-head);
  font-weight: 800; font-size: 20px;
  color: var(--c-text);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  letter-spacing: -0.5px;
}
.conf-badge {
  font-family: var(--f-mono);
  font-size: 9px; font-weight: 700;
  letter-spacing: 1px; text-transform: uppercase;
  border-radius: var(--r-max);
  padding: 4px 10px;
  flex-shrink: 0;
}
.conf-high { background: rgba(45,255,122,0.12); color: var(--c-green); border: 1px solid var(--c-green-4); }
.conf-medium { background: rgba(255,204,45,0.12); color: var(--c-gold); border: 1px solid var(--c-gold-3); }
.conf-low { background: rgba(255,75,75,0.1); color: var(--c-red); border: 1px solid var(--c-red-2); }

.result-body {
  background: var(--c-raise);
  border-left: 1px solid var(--c-border-2);
  border-right: 1px solid var(--c-border-2);
  padding: 16px 18px;
  border-bottom: 1px solid var(--c-border);
}

.rb-section { margin-bottom: 16px; }
.rb-section:last-child { margin-bottom: 0; }
.rb-title {
  font-family: var(--f-mono);
  font-size: 9px; font-weight: 700;
  letter-spacing: 2px; text-transform: uppercase;
  color: var(--c-text-3);
  margin-bottom: 10px;
  display: flex; align-items: center; gap: 6px;
}

/* Materials */
.mat-list { display: flex; flex-wrap: wrap; gap: 7px; }
.mat-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 11px;
  border-radius: var(--r-max);
  font-size: 11px; font-weight: 700;
  border: 1px solid;
  transition: var(--t-fast);
}
.mat-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.mat-h { background: rgba(45,255,122,0.08); color: var(--c-green); border-color: var(--c-green-4); }
.mat-m { background: rgba(255,204,45,0.08); color: var(--c-gold); border-color: var(--c-gold-3); }
.mat-l { background: rgba(255,255,255,0.04); color: var(--c-text-2); border-color: var(--c-border-3); }

/* Condition bar */
.cond-track { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
.cond-name { font-size: 13px; font-weight: 700; min-width: 110px; }
.cond-bar-wrap { flex: 1; height: 6px; background: var(--c-edge); border-radius: 3px; overflow: hidden; }
.cond-bar-fill { height: 100%; border-radius: 3px; transition: width 0.8s var(--t-slow); }

/* Parts */
.parts-list { display: flex; flex-wrap: wrap; gap: 6px; }
.part-tag {
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-sm);
  padding: 5px 10px;
  font-size: 11px;
  color: var(--c-text-2);
  font-weight: 500;
}

/* Hazards */
.hazard-list { display: flex; flex-wrap: wrap; gap: 6px; }
.hazard-tag {
  display: flex; align-items: center; gap: 5px;
  background: rgba(255,75,75,0.08);
  border: 1px solid var(--c-red-2);
  border-radius: var(--r-sm);
  padding: 5px 10px;
  font-size: 11px; font-weight: 700;
  color: var(--c-red);
}

/* Score block */
.score-block {
  background: var(--c-raise);
  border-left: 1px solid var(--c-border-2);
  border-right: 1px solid var(--c-border-2);
  border-bottom: 1px solid var(--c-border);
  padding: 18px;
}
.score-hdr {
  font-family: var(--f-mono);
  font-size: 9px; font-weight: 700;
  letter-spacing: 2px; text-transform: uppercase;
  color: var(--c-text-3); margin-bottom: 14px;
}
.score-layout { display: flex; align-items: center; gap: 18px; }

/* Gauge */
.gauge-wrap { position: relative; width: 80px; height: 80px; flex-shrink: 0; }
.gauge-wrap svg { transform: rotate(-90deg); }
.gauge-bg { fill: none; stroke: var(--c-edge); stroke-width: 7; }
.gauge-fill {
  fill: none;
  stroke-width: 7;
  stroke-linecap: round;
  transition: stroke-dashoffset 1.2s var(--t-slow), stroke 0.4s;
}
.gauge-label {
  position: absolute; inset: 0;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
}
.gauge-num {
  font-family: var(--f-head);
  font-weight: 900; font-size: 24px;
  line-height: 1; letter-spacing: -1px;
}
.gauge-denom { font-size: 9px; color: var(--c-text-3); font-family: var(--f-mono); font-weight: 700; }

/* Score rows */
.score-rows { flex: 1; }
.score-row {
  display: flex; justify-content: space-between; align-items: center;
  font-size: 12px; padding: 3px 0;
}
.score-row .sr-label { color: var(--c-text-2); }
.score-row .sr-val { font-weight: 700; font-family: var(--f-mono); }
.sr-pos { color: var(--c-green); }
.sr-neg { color: var(--c-red); }
.sr-neu { color: var(--c-text-3); }
.score-divider { height: 1px; background: var(--c-border); margin: 5px 0; }
.sr-total { font-family: var(--f-head); font-size: 15px !important; font-weight: 900; }

/* Verdict */
.verdict-block {
  border: 1px solid;
  border-top: none;
  border-radius: 0 0 var(--r-lg) var(--r-lg);
  padding: 18px;
  position: relative;
  overflow: hidden;
}
.verdict-block::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 1px;
}
.verdict-block.v-accept { border-color: var(--c-green-4); background: rgba(45,255,122,0.03); }
.verdict-block.v-accept::before { background: linear-gradient(90deg, transparent, var(--c-green), transparent); }
.verdict-block.v-warn   { border-color: var(--c-gold-3); background: rgba(255,204,45,0.03); }
.verdict-block.v-warn::before   { background: linear-gradient(90deg, transparent, var(--c-gold), transparent); }
.verdict-block.v-reject { border-color: var(--c-red-2); background: rgba(255,75,75,0.03); }
.verdict-block.v-reject::before { background: linear-gradient(90deg, transparent, var(--c-red), transparent); }

.verdict-top { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
.verdict-emoji { font-size: 32px; }
.verdict-text .vt-label {
  font-family: var(--f-head);
  font-size: 20px; font-weight: 900;
  letter-spacing: -0.5px; margin-bottom: 2px;
}
.verdict-text .vt-sub { font-size: 12px; color: var(--c-text-2); }

.verdict-msg {
  font-size: 13px;
  color: var(--c-text-2);
  line-height: 1.75;
  padding: 12px 14px;
  background: rgba(255,255,255,0.03);
  border-radius: var(--r-sm);
  border-left: 3px solid;
  margin-bottom: 14px;
}
.verdict-block.v-accept .verdict-msg { border-color: var(--c-green); }
.verdict-block.v-warn   .verdict-msg { border-color: var(--c-gold); }
.verdict-block.v-reject .verdict-msg { border-color: var(--c-red); }

.reward-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
.r-pill {
  display: flex; align-items: center; gap: 6px;
  padding: 6px 12px;
  border-radius: var(--r-max);
  font-size: 11px; font-weight: 700;
  border: 1px solid;
}
.r-pill.rp-coins { background: rgba(255,204,45,0.1); color: var(--c-gold); border-color: var(--c-gold-3); }
.r-pill.rp-high  { background: rgba(45,255,122,0.08); color: var(--c-green); border-color: var(--c-green-4); }
.r-pill.rp-hazard { background: rgba(255,75,75,0.08); color: var(--c-red); border-color: var(--c-red-2); }

.btn-proceed {
  width: 100%; padding: 15px;
  background: var(--c-green);
  color: var(--c-void);
  border-radius: var(--r-md);
  font-family: var(--f-head);
  font-size: 14px; font-weight: 900;
  letter-spacing: 0.3px;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: var(--t-slow);
  position: relative; overflow: hidden;
}
.btn-proceed::after { content: ''; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(255,255,255,0.15), transparent 60%); }
.btn-proceed:hover { transform: translateY(-2px); box-shadow: 0 12px 36px rgba(45,255,122,0.35); }

/* Fallback note */
.fallback-note {
  display: flex; align-items: center; gap: 6px;
  font-size: 10px; color: var(--c-text-3);
  margin-top: 10px; font-family: var(--f-mono);
  padding: 6px 10px;
  background: var(--c-base);
  border-radius: var(--r-sm);
  border: 1px solid var(--c-border);
}

/* ═══ SUBMIT FORM ═══ */
.form-card {
  background: var(--c-raise);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-lg);
  padding: 20px;
}
.form-card h3 {
  font-family: var(--f-head);
  font-size: 18px; font-weight: 800;
  letter-spacing: -0.3px;
  margin-bottom: 5px;
}
.form-card .fc-sub { font-size: 12px; color: var(--c-text-2); margin-bottom: 18px; }

.confirm-box {
  background: var(--c-green-5);
  border: 1px solid var(--c-green-4);
  border-radius: var(--r-md);
  padding: 13px 15px;
  margin-bottom: 16px;
}
.cb-name { font-weight: 700; font-size: 14px; color: var(--c-text); margin-bottom: 4px; }
.cb-coins { font-family: var(--f-mono); font-size: 11px; font-weight: 700; color: var(--c-green); }

.form-field { margin-bottom: 14px; }
.field-label {
  font-family: var(--f-mono);
  font-size: 9px; font-weight: 700;
  letter-spacing: 2px; text-transform: uppercase;
  color: var(--c-text-3);
  display: block; margin-bottom: 7px;
}
.field-input, .field-select, .field-textarea {
  width: 100%;
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-md);
  padding: 12px 14px;
  font-size: 14px;
  color: var(--c-text);
  outline: none;
  transition: border-color var(--t-fast);
  appearance: none;
}
.field-input:focus, .field-select:focus, .field-textarea:focus { border-color: var(--c-green); }
.field-select { cursor: pointer; }
.field-select option { background: var(--c-lift); }
.field-row { display: flex; gap: 8px; }
.field-row .field-input { flex: 2; }
.field-row .field-select { flex: 1; }

.coins-bar {
  background: rgba(255,204,45,0.06);
  border: 1px solid var(--c-gold-3);
  border-radius: var(--r-md);
  padding: 11px 14px;
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 14px;
}
.coins-bar-label { font-size: 12px; color: var(--c-text-2); }
.coins-bar-val {
  font-family: var(--f-head);
  font-size: 20px; font-weight: 900;
  color: var(--c-gold);
  letter-spacing: -0.5px;
}

.btn-submit {
  width: 100%; padding: 16px;
  background: linear-gradient(135deg, var(--c-green-3) 0%, var(--c-green-2) 100%);
  border: 1px solid var(--c-green-3);
  color: var(--c-void);
  border-radius: var(--r-md);
  font-family: var(--f-head);
  font-size: 14px; font-weight: 900;
  letter-spacing: 0.3px;
  transition: var(--t-slow);
}
.btn-submit:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(28,212,100,0.3); }
.btn-submit:disabled { opacity: 0.4; cursor: not-allowed; }

/* ═══ LOCATION SECTION ═══ */
.location-section {
  margin-top: 14px;
}
.location-label-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.location-btns {
  display: flex; gap: 8px;
}
.btn-loc {
  flex: 1;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  padding: 11px 10px;
  border-radius: var(--r-md);
  font-size: 12px; font-weight: 700;
  border: 1px solid var(--c-border-2);
  background: var(--c-lift);
  color: var(--c-text-2);
  transition: var(--t-mid);
  cursor: pointer;
}
.btn-loc:hover { border-color: var(--c-green); color: var(--c-green); background: rgba(45,255,122,0.06); }
.btn-loc.active { border-color: var(--c-green); color: var(--c-green); background: rgba(45,255,122,0.08); }
.btn-loc .loc-icon { font-size: 16px; }

.loc-status {
  font-size: 11px; font-family: var(--f-mono);
  color: var(--c-text-3);
  text-align: center;
  min-height: 16px;
  margin-bottom: 8px;
  transition: color var(--t-mid);
}
.loc-status.ok  { color: var(--c-green-2); }
.loc-status.err { color: var(--c-red); }

/* Map container */
#loc-map-wrap {
  display: none;
  border-radius: var(--r-md);
  overflow: hidden;
  border: 1px solid var(--c-border-2);
  margin-bottom: 10px;
  position: relative;
}
#loc-map { height: 220px; width: 100%; }

/* Leaflet dark-theme overrides */
#loc-map .leaflet-tile { filter: invert(0.85) hue-rotate(175deg) brightness(0.85) contrast(1.1); }
#loc-map .leaflet-control-zoom a {
  background: var(--c-raise) !important;
  color: var(--c-text) !important;
  border-color: var(--c-border-2) !important;
}
.map-confirm-hint {
  position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
  z-index: 500;
  background: rgba(8,12,9,0.88);
  border: 1px solid var(--c-green-4);
  border-radius: var(--r-max);
  padding: 5px 14px;
  font-size: 11px; font-family: var(--f-mono);
  color: var(--c-green-2);
  white-space: nowrap;
  pointer-events: none;
}

/* Address field */
.address-field-wrap { display: none; }
.address-field-wrap.on { display: block; }
.address-textarea {
  width: 100%;
  background: var(--c-lift);
  border: 1px solid var(--c-green-3);
  border-radius: var(--r-md);
  padding: 11px 14px;
  font-size: 13px;
  color: var(--c-text);
  outline: none;
  resize: vertical;
  min-height: 60px;
  transition: border-color var(--t-fast);
  font-family: var(--f-body);
  line-height: 1.5;
}
.address-textarea:focus { border-color: var(--c-green); }
.address-edit-hint {
  font-size: 10px; color: var(--c-text-3); font-family: var(--f-mono);
  margin-top: 4px;
}

/* ═══ RESCAN ═══ */
.btn-rescan {
  display: inline-flex; align-items: center; gap: 5px;
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  color: var(--c-text-2);
  border-radius: var(--r-sm);
  padding: 7px 12px;
  font-size: 11px; font-weight: 700;
  font-family: var(--f-mono);
  transition: var(--t-fast);
}
.btn-rescan:hover { color: var(--c-text); border-color: var(--c-border-3); }

/* ═══ SUCCESS ═══ */
.success-screen {
  display: none;
  padding: 60px 20px;
  text-align: center;
}
.success-screen.on { display: block; }

.success-glow-ring {
  width: 110px; height: 110px;
  margin: 0 auto 24px;
  position: relative;
  display: flex; align-items: center; justify-content: center;
}
.success-glow-ring::before,
.success-glow-ring::after {
  content: '';
  position: absolute;
  border-radius: 50%;
  border: 1.5px solid var(--c-green);
  animation: success-ring 2.5s ease-out infinite;
}
.success-glow-ring::before { width: 110px; height: 110px; }
.success-glow-ring::after  { width: 80px; height: 80px; animation-delay: 0.5s; opacity: 0.6; }
@keyframes success-ring {
  0% { transform: scale(0.8); opacity: 1; }
  100% { transform: scale(1.5); opacity: 0; }
}
.success-emoji {
  font-size: 50px;
  position: relative; z-index: 2;
  animation: success-bounce 0.6s var(--t-slow);
}
@keyframes success-bounce {
  0% { transform: scale(0.5); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
.success-h2 {
  font-family: var(--f-head);
  font-size: 30px; font-weight: 900;
  letter-spacing: -1px; margin-bottom: 10px;
}
.success-p {
  font-size: 14px; color: var(--c-text-2);
  line-height: 1.7; max-width: 280px;
  margin: 0 auto 24px;
}
.coins-badge {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(255,204,45,0.1);
  border: 1.5px solid var(--c-gold-3);
  border-radius: var(--r-max);
  padding: 12px 24px;
  font-family: var(--f-head);
  font-size: 24px; font-weight: 900;
  color: var(--c-gold);
  margin-bottom: 28px;
  letter-spacing: -0.5px;
}
.success-actions { display: flex; flex-direction: column; gap: 10px; max-width: 280px; margin: 0 auto; }
.btn-again {
  padding: 14px 20px;
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  color: var(--c-text-2);
  border-radius: var(--r-md);
  font-family: var(--f-head);
  font-size: 14px; font-weight: 700;
  transition: var(--t-fast);
}
.btn-again:hover { color: var(--c-text); border-color: var(--c-border-3); }

/* ═══ TOAST ═══ */
.toast {
  position: fixed; bottom: 24px; left: 50%;
  transform: translateX(-50%) translateY(80px);
  background: var(--c-float);
  border: 1px solid var(--c-border-3);
  color: var(--c-text);
  padding: 9px 18px;
  border-radius: var(--r-max);
  font-size: 12px; font-weight: 600;
  z-index: 9999;
  white-space: nowrap;
  transition: transform 0.3s var(--t-slow), opacity 0.3s;
  opacity: 0;
  backdrop-filter: blur(10px);
}
.toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }

/* ═══ UTILS ═══ */
.hidden { display: none !important; }
.result-top-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }

/* ═══ CHAT WIDGET ═══ */
.chat-fab {
  position: fixed;
  bottom: 24px; right: 16px;
  width: 50px; height: 50px;
  background: var(--c-green);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
  box-shadow: 0 8px 24px rgba(45,255,122,0.4);
  cursor: pointer;
  z-index: 300;
  transition: var(--t-slow);
  border: none;
}
.chat-fab:hover { transform: scale(1.1); box-shadow: 0 12px 36px rgba(45,255,122,0.5); }

.chat-drawer {
  position: fixed;
  bottom: 80px; right: 16px;
  width: min(340px, calc(100vw - 32px));
  background: var(--c-raise);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-xl);
  overflow: hidden;
  z-index: 290;
  transform: scale(0.9) translateY(20px);
  transform-origin: bottom right;
  opacity: 0;
  pointer-events: none;
  transition: all 0.25s var(--t-slow);
  box-shadow: 0 20px 60px rgba(0,0,0,0.5);
}
.chat-drawer.open { transform: scale(1) translateY(0); opacity: 1; pointer-events: all; }

.chat-hdr {
  display: flex; align-items: center; gap: 10px;
  padding: 14px 16px;
  background: var(--c-green-5);
  border-bottom: 1px solid var(--c-border);
}
.chat-avatar { font-size: 22px; }
.chat-title { font-weight: 800; font-size: 14px; }
.chat-status { font-size: 10px; color: var(--c-green-2); font-family: var(--f-mono); }
.chat-close {
  margin-left: auto;
  background: none;
  color: var(--c-text-2);
  font-size: 18px;
  width: 28px; height: 28px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  transition: var(--t-fast);
}
.chat-close:hover { background: rgba(255,255,255,0.1); color: var(--c-text); }

.chat-messages {
  height: 260px;
  overflow-y: auto;
  padding: 14px;
  display: flex; flex-direction: column; gap: 10px;
  scrollbar-width: thin;
}
.chat-msg { max-width: 85%; }
.chat-msg.bot { align-self: flex-start; }
.chat-msg.user { align-self: flex-end; }
.chat-bubble {
  padding: 9px 13px;
  border-radius: 14px;
  font-size: 13px;
  line-height: 1.6;
}
.bot .chat-bubble {
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  border-radius: 4px 14px 14px 14px;
  color: var(--c-text);
}
.user .chat-bubble {
  background: var(--c-green);
  color: var(--c-void);
  font-weight: 600;
  border-radius: 14px 14px 4px 14px;
}
.chat-typing {
  align-self: flex-start;
  display: flex; align-items: center; gap: 4px;
  padding: 8px 12px;
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  border-radius: 4px 14px 14px 14px;
}
.typing-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--c-text-3);
  animation: typing-bounce 1.2s ease infinite;
}
.typing-dot:nth-child(2) { animation-delay: 0.2s; }
.typing-dot:nth-child(3) { animation-delay: 0.4s; }
@keyframes typing-bounce { 0%, 80%, 100% { transform: scale(1); opacity: 0.5; } 40% { transform: scale(1.2); opacity: 1; } }

.chat-input-row {
  display: flex; gap: 8px;
  padding: 12px;
  border-top: 1px solid var(--c-border);
}
.chat-input {
  flex: 1;
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-sm);
  padding: 9px 12px;
  font-size: 13px;
  color: var(--c-text);
  outline: none;
  transition: border-color var(--t-fast);
}
.chat-input:focus { border-color: var(--c-green); }
.chat-input::placeholder { color: var(--c-text-3); }
.btn-chat-send {
  background: var(--c-green);
  color: var(--c-void);
  border-radius: var(--r-sm);
  width: 36px; height: 36px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
  transition: var(--t-fast);
  flex-shrink: 0;
}
.btn-chat-send:hover { transform: scale(1.08); }
</style>
</head>
<body>

<div class="app-shell" id="app">

  <!-- ── NAV ── -->
  <nav class="nav">
    <div class="nav-logo">
  <img src="/2.png" alt="GreenLoop Logo" style="width:30px;height:30px;object-fit:contain;border-radius:8px;">
  <div class="nav-wordmark">Green<em>Loop</em></div>
</div>
    <div class="wallet-chip" id="wallet-chip">🟡 <span id="wallet-bal">0</span></div>
  
  <div style="margin-top: 15px; text-align: center;">
    <a href="greenloop_wallet.php" 
       style="display: inline-flex; align-items: center; gap: 8px; 
              background: linear-gradient(135deg, #22c55e, #16a34a); 
              color: white; padding: 12px 24px; border-radius: 40px; 
              text-decoration: none; font-weight: bold; font-size: 14px;
              border: 1px solid #4ade80; transition: all 0.2s;
              box-shadow: 0 4px 12px rgba(34,197,94,0.3);"
       onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 20px rgba(34,197,94,0.4)'"
       onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 12px rgba(34,197,94,0.3)'">
        <span style="font-size: 20px;"></span>
        Wallet
        <span style="font-size: 16px;"></span>
    </a>
</div>
 </nav>

  <!-- ── API STATUS (Backend-managed key) ── -->
  <div class="api-zone" id="api-zone">
    <div class="api-hint">
      <span class="key-dot" id="key-dot"></span>
      <span>Connected to GreenLoop AI · Beta</span>
    </div>
  </div>

  <!-- ── MAIN CONTENT ── -->
  <div id="main-content">

    <!-- HERO -->
    <div class="hero">
      <div class="hero-tag">♻ Scrap Value Scanner</div>
      <h1 class="hero-title">What's Your<br>Scrap <span class="hl">Worth?</span></h1>
      <p class="hero-body">Snap a photo or describe your scrap item — AI identifies materials, detects hazards, and tells you your recycling value instantly.</p>
    </div>

    <!-- PROGRESS -->
    <div class="progress-track" id="progress-track">
      <div class="pt-seg active" id="pt-1"></div>
      <div class="pt-seg" id="pt-2"></div>
      <div class="pt-seg" id="pt-3"></div>
      <div class="pt-seg" id="pt-4"></div>
      <div class="pt-seg" id="pt-5"></div>
    </div>

    <!-- STEP 1: CAPTURE -->
    <div class="section" id="sec-capture">
      <div class="section-label">01 · Capture Item</div>
      <div class="input-panel">
        <div class="tab-row">
          <button class="tab-btn on" onclick="switchTab('photo')">📷 Photo</button>
          <button class="tab-btn" onclick="switchTab('text')">✏️ Describe</button>
          <button class="tab-btn" onclick="switchTab('catalog')">📋 Browse</button>
        </div>

        <!-- PHOTO TAB -->
        <div class="tab-pane on" id="tab-photo">
          <input type="file" id="file-input" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="onFileSelect(this)">
          <div class="drop-zone" id="drop-zone"
               onclick="document.getElementById('file-input').click()"
               ondragover="event.preventDefault();this.classList.add('over')"
               ondragleave="this.classList.remove('over')"
               ondrop="onDrop(event)">
            <div class="dz-icon">📷</div>
            <div class="dz-title">Take or Upload Photo</div>
            <p class="dz-sub">Point camera at scrap material for instant AI analysis</p>
            <div class="format-tags">
              <span class="fmt-tag">JPG</span>
              <span class="fmt-tag">PNG</span>
              <span class="fmt-tag">WebP</span>
              <span class="fmt-tag">Max 5MB</span>
            </div>
          </div>
          <div id="preview-wrap">
            <img id="img-preview" src="" alt="Preview">
            <div class="preview-controls">
              <input class="hint-input" id="img-hint" type="text" placeholder="Optional: 'old aircon compressor'">
              <button class="btn-clear" onclick="clearImg()">✕</button>
            </div>
          </div>
          <button class="btn-scan" id="btn-photo-scan" onclick="doScan('photo')" disabled>🔍 Scan & Analyze</button>
        </div>

        <!-- TEXT TAB -->
        <div class="tab-pane" id="tab-text">
          <div class="text-input-wrap">
            <input class="item-input" id="text-input" type="text"
                   placeholder="e.g. old electric fan with copper wire motor…"
                   maxlength="200" onkeydown="if(event.key==='Enter')doScan('text')">
          </div>
          <button class="btn-scan" onclick="doScan('text')">🔍 Analyze Item</button>
        </div>

        <!-- CATALOG TAB -->
        <div class="tab-pane" id="tab-catalog">
          <div class="catalog-wrap" id="catalog-list"></div>
        </div>

      </div>
    </div>

    <!-- STEP 2: ANALYZING -->
    <div class="section hidden" id="sec-analyzing">
      <div class="section-label">02 · AI Scanning</div>
      <div class="input-panel" style="padding:0">
        <div class="analyzing-wrap on">
          <div class="scan-animation">
            <div class="ring ring-1"></div>
            <div class="ring ring-2"></div>
            <div class="ring ring-3"></div>
            <div class="scan-core">♻</div>
          </div>
          <div class="scan-title" id="scan-label">Identifying item…</div>
          <div class="scan-sub">OPENROUTER · FREE MODELS</div>
          <div class="scan-steps">
            <div class="s-step active" id="ss-1"><div class="sdot"></div>Identifying item type…</div>
            <div class="s-step" id="ss-2"><div class="sdot"></div>Detecting materials…</div>
            <div class="s-step" id="ss-3"><div class="sdot"></div>Assessing condition &amp; hazards…</div>
            <div class="s-step" id="ss-4"><div class="sdot"></div>Calculating scrap value…</div>
          </div>
        </div>
      </div>
    </div>

    <!-- STEP 3–6: RESULTS -->
    <div class="section hidden" id="sec-result">
      <div class="result-top-bar">
        <div class="section-label" style="margin:0">03–06 · Analysis</div>
        <button class="btn-rescan" onclick="resetCapture()">↩ Scan Again</button>
      </div>

      <!-- Header -->
     <div class="result-header">
    <div class="res-icon-box" id="res-icon">
        <img src="/2.png" alt="Detected Item" class="w-full h-full object-contain" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <span class="hidden text-2xl">♻️</span>
    </div>
    <div class="res-meta">
        <div class="res-detected-tag">🔍 Detected</div>
        <div class="res-item-name" id="res-name">—</div>
    </div>
    <span class="conf-badge" id="res-conf">—</span>
</div>

      <!-- Body -->
      <div class="result-body">
        <div class="rb-section">
          <div class="rb-title">🧱 Materials</div>
          <div class="mat-list" id="res-mats"></div>
        </div>
        <div class="rb-section">
          <div class="rb-title">⚠️ Condition</div>
          <div class="cond-track">
            <span class="cond-name" id="res-cond-label">—</span>
            <div class="cond-bar-wrap"><div class="cond-bar-fill" id="res-cond-bar"></div></div>
          </div>
          <div style="font-size:11px;color:var(--c-text-3);margin-top:5px" id="res-cond-note"></div>
        </div>
        <div class="rb-section" id="hazard-section" style="display:none">
          <div class="rb-title">☢️ Hazard Flags</div>
          <div class="hazard-list" id="res-hazards"></div>
        </div>
        <div class="rb-section">
          <div class="rb-title">🔧 Recoverable Parts</div>
          <div class="parts-list" id="res-parts"></div>
        </div>
      </div>

      <!-- Score -->
      <div class="score-block">
        <div class="score-hdr">🧮 Scrap Value Score</div>
        <div class="score-layout">
          <div class="gauge-wrap">
            <svg width="80" height="80" viewBox="0 0 80 80">
              <circle class="gauge-bg" cx="40" cy="40" r="33"/>
              <circle class="gauge-fill" id="gauge-arc" cx="40" cy="40" r="33"
                      stroke-dasharray="207.3" stroke-dashoffset="207.3"
                      stroke="var(--c-green)"/>
            </svg>
            <div class="gauge-label">
              <span class="gauge-num" id="gauge-num">0</span>
              <span class="gauge-denom">/12</span>
            </div>
          </div>
          <div class="score-rows" id="score-rows"></div>
        </div>
      </div>

      <!-- Verdict -->
      <div class="verdict-block" id="verdict-block">
        <div class="verdict-top">
          <span class="verdict-emoji" id="verdict-emoji">—</span>
          <div class="verdict-text">
            <div class="vt-label" id="verdict-label">—</div>
            <div class="vt-sub" id="verdict-sub">—</div>
          </div>
        </div>
        <div class="verdict-msg" id="verdict-msg">Analyzing…</div>
        <div class="reward-row" id="reward-row"></div>
        <button class="btn-proceed" id="btn-proceed" onclick="goToSubmit()" style="display:none">♻️ Report This Item →</button>
        <div class="fallback-note hidden" id="fallback-note">⚡ AI unavailable — scored via rule-based engine</div>
      </div>
    </div>

    <!-- STEP 7: SUBMIT -->
    <div class="section hidden" id="sec-submit">
      <div class="section-label">07 · Submit Report</div>
      <div class="form-card">
        <h3>♻️ Submit GreenLoop Report</h3>
        <p class="fc-sub">Confirm details and our team will schedule pickup.</p>
        <div class="confirm-box">
          <div class="cb-name" id="confirm-name">—</div>
          <div class="cb-coins" id="confirm-coins">—</div>
        </div>
        <div class="form-field">
          <label class="field-label">Quantity</label>
          <div class="field-row">
            <input class="field-input" type="number" id="qty" min="0.1" step="0.1" value="1" oninput="updateCoinsPreview()">
            <select class="field-select" id="unit">
              <option value="piece">piece</option>
              <option value="kg">kg</option>
              <option value="meter">meter</option>
              <option value="set">set</option>
            </select>
          </div>
        </div>
        <div class="coins-bar">
          <span class="coins-bar-label">Estimated Earnings</span>
          <span class="coins-bar-val" id="coins-preview">🟡 0</span>
        </div>
        <div class="form-field">
          <label class="field-label">Notes <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--c-text-3);font-size:10px">(optional)</span></label>
          <textarea class="field-textarea" id="notes" rows="3" placeholder="Condition details, location, extra info…"></textarea>
        </div>

        <!-- ── LOCATION ─────────────────────────────────── -->
        <div class="location-section">
          <div class="field-label" style="margin-bottom:8px">Pickup Location <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--c-text-3);font-size:10px">(optional)</span></div>
          <div class="location-btns">
            <button type="button" class="btn-loc" id="btn-gps" onclick="useGPS()">
              <span class="loc-icon">📍</span> Use My Location
            </button>
            <button type="button" class="btn-loc" id="btn-pin" onclick="openMapPicker()">
              <span class="loc-icon">🗺️</span> Pin on Map
            </button>
          </div>
          <div class="loc-status" id="loc-status"></div>

          <!-- Leaflet map (shown when pinning) -->
          <div id="loc-map-wrap">
            <div id="loc-map"></div>
            <div class="map-confirm-hint">Drag map or click to reposition pin · tap ✓ to confirm</div>
            <button type="button" onclick="confirmMapPin()"
              style="position:absolute;bottom:10px;right:10px;z-index:500;
                     background:var(--c-green);color:var(--c-void);border:none;
                     border-radius:var(--r-max);padding:7px 16px;font-weight:800;
                     font-size:12px;cursor:pointer;font-family:var(--f-head);">
              ✓ Confirm
            </button>
          </div>

          <!-- Editable resolved address -->
          <div class="address-field-wrap" id="address-field-wrap">
            <textarea class="address-textarea" id="pickup-address-input"
              placeholder="Resolving address…" rows="2"></textarea>
            <div class="address-edit-hint">✏️ You can edit this address if it's not accurate</div>
          </div>

          <!-- Hidden inputs carried to submitReport() -->
          <input type="hidden" id="pickup-lat" value="">
          <input type="hidden" id="pickup-lng" value="">
        </div>
        <!-- ── /LOCATION ──────────────────────────────────── -->

        <button class="btn-submit" id="btn-submit" onclick="submitReport()">♻️ Submit GreenLoop Report</button>
      </div>
    </div>

  </div><!-- #main-content -->

  <!-- SUCCESS -->
  <div class="success-screen" id="success-screen">
    <div class="success-glow-ring">
      <div class="success-emoji">🎉</div>
    </div>
    <h2 class="success-h2">Report Submitted!</h2>
    <p class="success-p">Our GreenLoop team will reach out to schedule scrap pickup. You'll earn coins once items are verified on-site.</p>
    <div class="coins-badge">🟡 <span id="earned-coins">0</span> Coins Pending</div>
    <div class="success-actions">
      <button class="btn-again" onclick="resetAll()">♻️ Scan Another Item</button>
    </div>
  </div>

</div><!-- .app-shell -->

<!-- CHAT -->
<button class="chat-fab" onclick="toggleChat()" id="chat-fab">💬</button>
<div class="chat-drawer" id="chat-drawer">
  <div class="chat-hdr">
    <img src="/2.png" alt="GreenLoop AI" style="width:30px;height:30px;object-fit:contain;border-radius:8px;">
    <div>
      <div class="chat-title">GreenLoop AI</div>
      <div class="chat-status">● Online · Beta</div>
    </div>
    <button class="chat-close" onclick="toggleChat()">✕</button>
  </div>
  <div class="chat-messages" id="chat-msgs">
    <div class="chat-msg bot">
      <div class="chat-bubble">Kamusta! I'm GreenLoop AI 👋 Describe any scrap item and I'll help with recycling value and tips!</div>
    </div>
  </div>
  <div class="chat-input-row">
    <input class="chat-input" id="chat-input" type="text" placeholder="Ask about scrap materials…"
           onkeydown="if(event.key==='Enter')sendChat()">
    <button class="btn-chat-send" onclick="sendChat()">➤</button>
  </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
// ══════════════════════════════════════════════════════════════
//  GreenLoop AI — Client-Side Logic
//  Calls: greenloop_ai_openrouter.php on server
//  API key is managed securely on backend
// ══════════════════════════════════════════════════════════════

const API_ENDPOINT = 'greenloop_ai_ask.php';

// ── CATALOG DATA ──────────────────────────────────────────────
const CATALOG = {
  'Electrical ⚡': [
    { id:1, name:'Copper Wire', coins:40, unit:'kg' },
    { id:2, name:'Electric Motor', coins:25, unit:'piece' },
    { id:3, name:'Circuit Breaker', coins:15, unit:'piece' },
    { id:4, name:'Transformer', coins:30, unit:'piece' },
    { id:5, name:'Old Wiring / Cables', coins:20, unit:'kg' },
  ],
  'Appliance 🔌': [
    { id:6, name:'Electric Fan', coins:20, unit:'piece' },
    { id:7, name:'Air Conditioner', coins:60, unit:'piece' },
    { id:8, name:'Refrigerator', coins:50, unit:'piece' },
    { id:9, name:'Washing Machine', coins:45, unit:'piece' },
    { id:10, name:'Water Heater', coins:30, unit:'piece' },
  ],
  'Automotive 🚗': [
    { id:11, name:'Car Battery', coins:35, unit:'piece' },
    { id:12, name:'Alternator', coins:25, unit:'piece' },
    { id:13, name:'Radiator', coins:30, unit:'piece' },
  ],
  'Hardware 🔧': [
    { id:14, name:'Scrap Aluminum', coins:15, unit:'kg' },
    { id:15, name:'Scrap Steel / Iron', coins:8, unit:'kg' },
    { id:16, name:'Old Metal Pipes', coins:12, unit:'kg' },
  ],
};

// Material tiers for display
const MAT_H = ['copper','gold','silver','brass','bronze','motor_coil','transformer_core'];
const MAT_M = ['aluminum','steel','iron','lead','lithium','circuit_board','tin'];

// ── STATE ─────────────────────────────────────────────────────
let walletBalance   = 0;
let selectedFile    = null;
let aiResult        = null;   // last full server response
let selectedCoins   = 0;
let selectedUnit    = 'piece';
let selectedName    = '';
let chatHistory     = [];
let chatOpen        = false;

// ── INIT ──────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
  buildCatalog();
  // Load saved wallet balance if any
  const saved = localStorage.getItem('gl_wallet');
  if (saved) {
    walletBalance = parseInt(saved) || 0;
    document.getElementById('wallet-bal').textContent = walletBalance;
  }
});

// ── TABS ──────────────────────────────────────────────────────
function switchTab(name) {
  ['photo','text','catalog'].forEach((t,i) => {
    document.querySelectorAll('.tab-btn')[i].classList.toggle('on', t === name);
    document.getElementById('tab-'+t).classList.toggle('on', t === name);
  });
}

// ── IMAGE HANDLING ────────────────────────────────────────────
function onFileSelect(input) { 
  if (input.files[0]) loadFile(input.files[0]); 
}

function onDrop(e) {
  e.preventDefault();
  document.getElementById('drop-zone').classList.remove('over');
  const f = e.dataTransfer.files[0];
  if (f && f.type.startsWith('image/')) loadFile(f);
}

function loadFile(file) {
  if (file.size > 5 * 1024 * 1024) { 
    toast('⚠️ Image must be under 5MB'); 
    return; 
  }
  selectedFile = file;
  const r = new FileReader();
  r.onload = e => {
    document.getElementById('img-preview').src = e.target.result;
    document.getElementById('preview-wrap').style.display = 'block';
    document.getElementById('drop-zone').style.display = 'none';
    document.getElementById('btn-photo-scan').disabled = false;
  };
  r.readAsDataURL(file);
}

function clearImg() {
  selectedFile = null;
  document.getElementById('img-preview').src = '';
  document.getElementById('preview-wrap').style.display = 'none';
  document.getElementById('drop-zone').style.display = 'block';
  document.getElementById('btn-photo-scan').disabled = true;
  document.getElementById('file-input').value = '';
}

// ── CATALOG ───────────────────────────────────────────────────
function buildCatalog() {
  let html = '';
  for (const [cat, items] of Object.entries(CATALOG)) {
    html += `<div class="cat-group-hdr">${cat}</div><div class="cat-grid">`;
    items.forEach(item => {
      html += `<div class="cat-card" onclick="selectCatalog(this)"
        data-name="${item.name}" data-coins="${item.coins}" data-unit="${item.unit}">
        <div class="cat-check">✓</div>
        <div class="cat-name">${item.name}</div>
        <div class="cat-coins">🟡 ${item.coins} / ${item.unit}</div>
      </div>`;
    });
    html += '</div>';
  }
  document.getElementById('catalog-list').innerHTML = html;
}

function selectCatalog(card) {
  document.querySelectorAll('.cat-card').forEach(c => c.classList.remove('sel'));
  card.classList.add('sel');
  selectedName  = card.dataset.name;
  selectedCoins = parseFloat(card.dataset.coins);
  selectedUnit  = card.dataset.unit;
  goToSubmitDirect();
}

// ── LOCATION ──────────────────────────────────────────────────
let leafletMap   = null;
let leafletMarker = null;
let locLat = null;
let locLng = null;

function setLocStatus(msg, cls = '') {
  const el = document.getElementById('loc-status');
  el.textContent = msg;
  el.className = 'loc-status ' + cls;
}

function setLocCoords(lat, lng) {
  locLat = lat; locLng = lng;
  document.getElementById('pickup-lat').value = lat;
  document.getElementById('pickup-lng').value = lng;
}

async function reverseGeocode(lat, lng) {
  setLocStatus('Resolving address…');
  document.getElementById('address-field-wrap').classList.remove('on');
  try {
    const r = await fetch(
      `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`,
      { headers: { 'Accept-Language': 'en', 'User-Agent': 'GreenLoop-App/1.0' } }
    );
    const d = await r.json();
    const addr = d.display_name || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
    document.getElementById('pickup-address-input').value = addr;
    document.getElementById('address-field-wrap').classList.add('on');
    setLocStatus('📍 Location captured — edit address if needed', 'ok');
  } catch(e) {
    // fallback: show raw coords
    document.getElementById('pickup-address-input').value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
    document.getElementById('address-field-wrap').classList.add('on');
    setLocStatus('Could not resolve address — you can type it manually', 'err');
  }
}

function useGPS() {
  if (!navigator.geolocation) {
    setLocStatus('Geolocation not supported on this device', 'err'); return;
  }
  setLocStatus('Getting your location…');
  document.getElementById('btn-gps').classList.add('active');
  document.getElementById('btn-pin').classList.remove('active');
  document.getElementById('loc-map-wrap').style.display = 'none';
  if (leafletMap) { leafletMap.remove(); leafletMap = null; leafletMarker = null; }

  navigator.geolocation.getCurrentPosition(
    pos => {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      setLocCoords(lat, lng);
      reverseGeocode(lat, lng);
    },
    err => {
      document.getElementById('btn-gps').classList.remove('active');
      const msgs = { 1:'Location permission denied', 2:'Location unavailable', 3:'Request timed out' };
      setLocStatus(msgs[err.code] || 'Could not get location', 'err');
    },
    { enableHighAccuracy: true, timeout: 10000 }
  );
}

function openMapPicker() {
  document.getElementById('btn-pin').classList.add('active');
  document.getElementById('btn-gps').classList.remove('active');
  const wrap = document.getElementById('loc-map-wrap');
  wrap.style.display = 'block';
  setLocStatus('Click/drag the map to set pickup location');

  // Default center: Surigao del Sur, PH (approximate)
  const defaultLat = 9.0, defaultLng = 126.05;

  if (!leafletMap) {
    leafletMap = L.map('loc-map', { zoomControl: true }).setView(
      locLat ? [locLat, locLng] : [defaultLat, defaultLng], 14
    );
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors', maxZoom: 19
    }).addTo(leafletMap);

    // Custom green pin icon
    const greenIcon = L.divIcon({
      html: '<div style="background:#2dff7a;width:18px;height:18px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2px solid #052e16;box-shadow:0 2px 8px rgba(45,255,122,0.5)"></div>',
      iconSize: [18, 18], iconAnchor: [9, 18], className: ''
    });

    const startLat = locLat || defaultLat;
    const startLng = locLng || defaultLng;
    leafletMarker = L.marker([startLat, startLng], { icon: greenIcon, draggable: true }).addTo(leafletMap);

    leafletMarker.on('dragend', e => {
      const p = e.target.getLatLng();
      setLocCoords(p.lat, p.lng);
    });
    leafletMap.on('click', e => {
      leafletMarker.setLatLng(e.latlng);
      setLocCoords(e.latlng.lat, e.latlng.lng);
    });

    // Fix Leaflet tile rendering after display:block
    setTimeout(() => leafletMap.invalidateSize(), 100);
  } else {
    leafletMap.invalidateSize();
  }
}

function confirmMapPin() {
  if (!locLat || !locLng) {
    const c = leafletMarker ? leafletMarker.getLatLng() : null;
    if (!c) { setLocStatus('Please click the map to set a location', 'err'); return; }
    setLocCoords(c.lat, c.lng);
  }
  document.getElementById('loc-map-wrap').style.display = 'none';
  reverseGeocode(locLat, locLng);
}

// ── SCAN ──────────────────────────────────────────────────────
async function doScan(mode) {
  setProgress(2);
  document.getElementById('sec-capture').classList.add('hidden');
  document.getElementById('sec-analyzing').classList.remove('hidden');
  animateScanSteps();

  try {
    let response;
    if (mode === 'photo') {
      if (!selectedFile) {
        resetCapture();
        toast('⚠️ Please select an image first');
        return;
      }
      const fd = new FormData();
      fd.append('scrap_image', selectedFile);
      const hint = document.getElementById('img-hint').value.trim();
      if (hint) fd.append('item_description', hint);
      
      response = await fetch(API_ENDPOINT, { 
        method: 'POST', 
        body: fd 
      });
    } else {
      const desc = document.getElementById('text-input').value.trim();
      if (desc.length < 3) { 
        resetCapture(); 
        toast('⚠️ Please describe the item (at least 3 characters)');
        return; 
      }
      response = await fetch(API_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ item_description: desc, mode: 'scan' })
      });
    }

    if (!response.ok) {
      const errText = await response.text();
      throw new Error(`Server error ${response.status}: ${errText}`);
    }
    
    const data = await response.json();

    if (data.error) throw new Error(data.error);
    renderResult(data);

  } catch (err) {
    console.error('Scan error:', err);
    renderError(err.message || 'Analysis failed. Please try again.');
  }
}

function animateScanSteps() {
  const ids = ['ss-1','ss-2','ss-3','ss-4'];
  const labels = ['Identifying item type…','Detecting materials…','Assessing hazards…','Calculating scrap value…'];
  let i = 0;
  const t = setInterval(() => {
    if (i > 0) { 
      const prev = document.getElementById(ids[i-1]); 
      prev.classList.remove('active'); 
      prev.classList.add('done'); 
    }
    if (i < ids.length) {
      document.getElementById(ids[i]).classList.add('active');
      document.getElementById('scan-label').textContent = labels[i];
    } else { 
      clearInterval(t); 
    }
    i++;
  }, 650);
  window._scanInterval = t;
}

// ── RENDER RESULT ─────────────────────────────────────────────
function renderResult(data) {
  clearInterval(window._scanInterval);
  aiResult      = data;
  selectedName  = data.item_name || 'Scrap Item';
  selectedCoins = data.green_coins || 0;
  selectedUnit  = data.unit || 'piece';

  document.getElementById('sec-analyzing').classList.add('hidden');
  document.getElementById('sec-result').classList.remove('hidden');
  setProgress(4);

  // Header
  document.getElementById('res-icon').textContent = itemIcon(data.item_name);
  document.getElementById('res-name').textContent = data.item_name || 'Unknown Item';

  const conf = (data.ai_confidence || 'low').toLowerCase();
  const confEl = document.getElementById('res-conf');
  confEl.textContent = conf.charAt(0).toUpperCase() + conf.slice(1);
  confEl.className = `conf-badge conf-${conf === 'high' ? 'high' : conf === 'medium' ? 'medium' : 'low'}`;

  // Materials
  const mats = data.visible_materials || [];
  document.getElementById('res-mats').innerHTML = mats.length
    ? mats.map(m => {
        const cls = MAT_H.includes(m) ? 'mat-h' : MAT_M.includes(m) ? 'mat-m' : 'mat-l';
        const tag = MAT_H.includes(m) ? 'high' : MAT_M.includes(m) ? 'med' : 'low';
        return `<span class="mat-chip ${cls}"><span class="mat-dot"></span>${m}<span style="opacity:0.5;font-size:9px;margin-left:3px">${tag}</span></span>`;
      }).join('')
    : '<span style="color:var(--c-text-3);font-size:12px">No identifiable materials</span>';

  // Condition
  const cond = data.condition || 'Fair';
  const condPct  = { 'Good':90, 'Fair':65, 'Damaged':35, 'Severely Damaged':10 }[cond] || 50;
  const condClr  = { 'Good':'var(--c-green)', 'Fair':'var(--c-gold)', 'Damaged':'#f97316', 'Severely Damaged':'var(--c-red)' }[cond] || 'var(--c-green)';
  const condNote = { 'Good':'Functional, clean', 'Fair':'Worn but intact', 'Damaged':'Broken / missing parts', 'Severely Damaged':'Crushed / burned / corroded' }[cond] || '';
  document.getElementById('res-cond-label').textContent = cond;
  document.getElementById('res-cond-note').textContent = condNote;
  setTimeout(() => {
    const bar = document.getElementById('res-cond-bar');
    bar.style.width = condPct + '%';
    bar.style.background = condClr;
  }, 200);

  // Hazards
  const hazards = data.hazard_flags || [];
  const hazSec = document.getElementById('hazard-section');
  if (hazards.length) {
    hazSec.style.display = '';
    document.getElementById('res-hazards').innerHTML = hazards.map(h =>
      `<span class="hazard-tag">⚠ ${h.replace(/_/g,' ')}</span>`
    ).join('');
  } else { hazSec.style.display = 'none'; }

  // Recoverable parts
  const parts = data.recoverable_parts || [];
  document.getElementById('res-parts').innerHTML = parts.length
    ? parts.map(p => `<span class="part-tag">${p}</span>`).join('')
    : '<span style="color:var(--c-text-3);font-size:12px">None identified</span>';

  // Score gauge
  const score = Math.min(12, Math.max(0, data.total_score || 0));
  setTimeout(() => {
    const arc = document.getElementById('gauge-arc');
    arc.style.strokeDashoffset = 207.3 - (207.3 * score / 12);
    arc.style.stroke = score >= 8 ? 'var(--c-green)' : score >= 4 ? 'var(--c-gold)' : 'var(--c-red)';
    document.getElementById('gauge-num').textContent = score;
  }, 300);

  // Score breakdown
  const cm = data.condition_modifier || 0;
  const cb = data.category_bonus || 0;
  const hp = data.hazard_penalty || 0;
  document.getElementById('score-rows').innerHTML = `
    <div class="score-row"><span class="sr-label">Materials</span><span class="sr-val sr-pos">+${data.material_score || 0}</span></div>
    <div class="score-row"><span class="sr-label">Condition (${cond})</span><span class="sr-val ${cm > 0 ? 'sr-pos' : cm < 0 ? 'sr-neg' : 'sr-neu'}">${cm > 0 ? '+' : ''}${cm}</span></div>
    <div class="score-row"><span class="sr-label">Category bonus</span><span class="sr-val sr-pos">+${cb}</span></div>
    ${hp < 0 ? `<div class="score-row"><span class="sr-label">Hazard penalty</span><span class="sr-val sr-neg">${hp}</span></div>` : ''}
    <div class="score-divider"></div>
    <div class="score-row"><span class="sr-label" style="font-weight:800">Total</span><span class="sr-val sr-total" style="color:${score>=8?'var(--c-green)':score>=4?'var(--c-gold)':'var(--c-red)'}">${score}/12</span></div>`;

  // Verdict
  const vc = data.verdict_class || 'reject';
  const vb = document.getElementById('verdict-block');
  vb.className = `verdict-block v-${vc}`;
  document.getElementById('verdict-emoji').textContent = vc === 'accept' ? '✅' : vc === 'warn' ? '⚠️' : '❌';
  document.getElementById('verdict-label').textContent = data.verdict_label || '—';
  document.getElementById('verdict-sub').textContent = data.recyclability || '—';
  document.getElementById('verdict-msg').textContent = data.message || data.verdict_label;

  // Reward chips
  let chips = '';
  if (data.accepted && selectedCoins > 0) chips += `<span class="r-pill rp-coins">🟡 ~${selectedCoins} coins/${selectedUnit}</span>`;
  if (data.accepted && score >= 8)        chips += `<span class="r-pill rp-high">💰 High recovery value</span>`;
  if (hazards.length)                     chips += `<span class="r-pill rp-hazard">☢️ Handle carefully</span>`;
  document.getElementById('reward-row').innerHTML = chips;

  // Proceed button
  document.getElementById('btn-proceed').style.display = data.accepted ? 'flex' : 'none';

  // Fallback note
  const fnEl = document.getElementById('fallback-note');
  fnEl.classList.toggle('hidden', !data.fallback_used);

  document.getElementById('sec-result').scrollIntoView({ behavior: 'smooth' });
}

function renderError(msg) {
  clearInterval(window._scanInterval);
  document.getElementById('sec-analyzing').classList.add('hidden');
  document.getElementById('sec-capture').classList.remove('hidden');
  setProgress(1);
  toast('⚠️ ' + msg);
}

function itemIcon(name) {
  if (!name) return '♻️';
  const n = name.toLowerCase();
  if (n.includes('fan') || n.includes('aircon') || n.includes(' ac')) return '🌀';
  if (n.includes('motor') || n.includes('engine')) return '⚙️';
  if (n.includes('battery')) return '🔋';
  if (n.includes('wire') || n.includes('cable') || n.includes('copper')) return '🔌';
  if (n.includes('tv') || n.includes('monitor') || n.includes('computer')) return '📺';
  if (n.includes('car') || n.includes('auto')) return '🚗';
  if (n.includes('refrigerator') || n.includes('fridge') || n.includes('ref')) return '🧊';
  if (n.includes('pump')) return '💧';
  if (n.includes('iron') || n.includes('steel') || n.includes('pipe')) return '🔩';
  return '♻️';
}

// ── SUBMIT FLOW ───────────────────────────────────────────────
function goToSubmit() {
  setProgress(5);
  document.getElementById('sec-submit').classList.remove('hidden');
  document.getElementById('unit').value = selectedUnit;
  updateForm();
  document.getElementById('sec-submit').scrollIntoView({ behavior: 'smooth' });
}

function goToSubmitDirect() {
  setProgress(5);
  document.getElementById('sec-capture').classList.add('hidden');
  document.getElementById('sec-submit').classList.remove('hidden');
  document.getElementById('unit').value = selectedUnit;
  updateForm();
  document.getElementById('sec-submit').scrollIntoView({ behavior: 'smooth' });
}

function updateForm() {
  document.getElementById('confirm-name').textContent = '♻️ ' + (selectedName || '—');
  document.getElementById('confirm-coins').textContent = `🟡 ${selectedCoins} Green Coins per ${selectedUnit}`;
  updateCoinsPreview();
}

function updateCoinsPreview() {
  const qty = parseFloat(document.getElementById('qty').value) || 1;
  document.getElementById('coins-preview').textContent = `🟡 ${Math.round(selectedCoins * qty)}`;
}

// Replace the existing submitReport() function with this:
async function submitReport() {
  const btn = document.getElementById('btn-submit');
  const originalText = btn.textContent;
  btn.disabled = true;
  btn.textContent = '⏳ Submitting…';

  const qty = parseFloat(document.getElementById('qty').value) || 1;
  const unit = document.getElementById('unit').value;
  const notes = document.getElementById('notes').value.trim();
  const earned = Math.round(selectedCoins * qty);

  // Build the payload matching greenloop_submit.php expectations
  const payload = {
    item_id: aiResult?.item_id || null,
    item_name_custom: selectedName || 'Scrap Item',
    quantity: qty,
    unit: unit,
    client_notes: notes,
    ai_assessment: aiResult?.message || aiResult?.verdict_label || '',
    ai_accepted: aiResult?.accepted ? 1 : 0,
    estimated_green_coins: selectedCoins,
    pickup_latitude:  document.getElementById('pickup-lat').value  || null,
    pickup_longitude: document.getElementById('pickup-lng').value  || null,
    pickup_address:   document.getElementById('pickup-address-input').value.trim() || null,
  };

  // Optional: Add booking_id if available
  if (aiResult?.booking_id) {
    payload.booking_id = aiResult.booking_id;
  }

  try {
    const response = await fetch('greenloop_submit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    // Check if response is JSON
    const contentType = response.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      const text = await response.text();
      console.error('Server returned non-JSON:', text.substring(0, 200));
      throw new Error('Server error - please try again');
    }

    const data = await response.json();

    if (!response.ok) {
      throw new Error(data.error || 'Submission failed');
    }

    // Only update local wallet after successful DB save
    walletBalance += earned;
    localStorage.setItem('gl_wallet', walletBalance);
    
    // Update wallet display with animation
    const walletSpan = document.getElementById('wallet-bal');
    walletSpan.textContent = walletBalance;
    const chip = document.getElementById('wallet-chip');
    chip.classList.add('bump');
    setTimeout(() => chip.classList.remove('bump'), 400);

    // Hide main content and show success screen
    document.getElementById('main-content').style.display = 'none';
    document.getElementById('earned-coins').textContent = earned;
    document.getElementById('success-screen').classList.add('on');
    window.scrollTo({ top: 0, behavior: 'smooth' });

    // Reset button state
    btn.disabled = false;
    btn.textContent = originalText;

    // Optional: Show actual report ID from server
    if (data.report_id) {
      console.log('Report submitted, ID:', data.report_id);
    }

  } catch (error) {
    console.error('Submit error:', error);
    toast('❌ Failed to submit: ' + error.message);
    
    // Reset button
    btn.disabled = false;
    btn.textContent = originalText;
  }
}

// ── RESET ─────────────────────────────────────────────────────
function resetCapture() {
  document.getElementById('sec-analyzing').classList.add('hidden');
  document.getElementById('sec-result').classList.add('hidden');
  document.getElementById('sec-submit').classList.add('hidden');
  document.getElementById('sec-capture').classList.remove('hidden');
  setProgress(1);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetAll() {
  resetCapture();
  clearImg();
  document.getElementById('text-input').value = '';
  document.querySelectorAll('.cat-card').forEach(c => c.classList.remove('sel'));
  document.getElementById('success-screen').classList.remove('on');
  document.getElementById('main-content').style.display = '';
  document.getElementById('notes').value = '';
  aiResult = null; 
  selectedName = ''; 
  selectedCoins = 0;
  locLat = null; locLng = null;
  document.getElementById('pickup-lat').value = '';
  document.getElementById('pickup-lng').value = '';
  document.getElementById('pickup-address-input').value = '';
  document.getElementById('address-field-wrap').classList.remove('on');
  document.getElementById('loc-status').textContent = '';
  document.getElementById('loc-map-wrap').style.display = 'none';
  document.getElementById('btn-gps').classList.remove('active');
  document.getElementById('btn-pin').classList.remove('active');
  if (leafletMap) { leafletMap.remove(); leafletMap = null; leafletMarker = null; }
}

// ── PROGRESS ──────────────────────────────────────────────────
function setProgress(step) {
  for (let i = 1; i <= 5; i++) {
    const el = document.getElementById('pt-' + i);
    el.classList.remove('active','done');
    if (i < step) el.classList.add('done');
    else if (i === step) el.classList.add('active');
  }
}

// ── CHAT ──────────────────────────────────────────────────────
function toggleChat() {
  chatOpen = !chatOpen;
  document.getElementById('chat-drawer').classList.toggle('open', chatOpen);
  document.getElementById('chat-fab').textContent = chatOpen ? '✕' : '💬';
  if (chatOpen) document.getElementById('chat-input').focus();
}

async function sendChat() {
  const input = document.getElementById('chat-input');
  const msg   = input.value.trim();
  if (!msg) return;
  input.value = '';

  appendChatMsg(msg, 'user');
  const typing = appendTyping();

  chatHistory.push({ role: 'user', content: msg });

  try {
    const res = await fetch(API_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ mode: 'chat', message: msg, history: chatHistory })
    });
    const data = await res.json();
    typing.remove();
    const reply = data.response || "Sorry, I couldn't connect. Please try again!";
    appendChatMsg(reply, 'bot');
    chatHistory.push({ role: 'assistant', content: reply });
    if (chatHistory.length > 20) chatHistory = chatHistory.slice(-20);
  } catch (e) {
    typing.remove();
    appendChatMsg("Connection issue — please try again in a moment!", 'bot');
  }
}

function appendChatMsg(text, role) {
  const msgs = document.getElementById('chat-msgs');
  const el = document.createElement('div');
  el.className = `chat-msg ${role}`;
  el.innerHTML = `<div class="chat-bubble">${escHtml(text)}</div>`;
  msgs.appendChild(el);
  msgs.scrollTop = msgs.scrollHeight;
  return el;
}

function appendTyping() {
  const msgs = document.getElementById('chat-msgs');
  const el = document.createElement('div');
  el.className = 'chat-msg bot';
  el.innerHTML = `<div class="chat-typing"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div>`;
  msgs.appendChild(el);
  msgs.scrollTop = msgs.scrollHeight;
  return el;
}

function escHtml(str) {
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── TOAST ─────────────────────────────────────────────────────
function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2600);
}
</script>
</body>
</html>