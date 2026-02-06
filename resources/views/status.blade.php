<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuse // Circuit Breaker Status</title>
    <meta name="fuse-data-url" content="{{ route('fuse.status.data') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist+Mono:wght@400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        mono: ['Geist Mono', 'monospace'],
                        display: ['Outfit', 'sans-serif'],
                    },
                    colors: {
                        cyber: {
                            black: '#0a0a0f',
                            darker: '#0d0d14',
                            dark: '#12121a',
                            gray: '#1a1a24',
                            light: '#2a2a3a',
                        },
                        neon: {
                            cyan: '#00f0ff',
                            green: '#00ff88',
                            red: '#ff3366',
                            amber: '#ffaa00',
                            purple: '#a855f7',
                        },
                        day: {
                            bg: '#f8fafc',
                            surface: '#ffffff',
                            border: '#e2e8f0',
                            muted: '#64748b',
                            cyan: '#0891b2',
                            green: '#059669',
                            red: '#dc2626',
                            amber: '#d97706',
                            purple: '#7c3aed',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        /* Dark mode body */
        .dark body { background: #0a0a0f; color: #ffffff; }
        html:not(.dark) body { background: #f8fafc; color: #0f172a; }

        /* Grid background */
        .dark .grid-bg {
            background-image:
                linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
        }
        html:not(.dark) .grid-bg {
            background-image:
                linear-gradient(rgba(0, 0, 0, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 0, 0, 0.02) 1px, transparent 1px);
            background-size: 50px 50px;
        }

        /* Scan line effect - dark only */
        .dark .scanlines::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: repeating-linear-gradient(
                0deg, transparent, transparent 2px,
                rgba(0, 0, 0, 0.1) 2px, rgba(0, 0, 0, 0.1) 4px
            );
            pointer-events: none;
            z-index: 1000;
        }
        html:not(.dark) .scanlines::before { display: none; }

        /* Dark mode glow effects */
        .dark .glow-cyan { box-shadow: 0 0 30px rgba(0, 240, 255, 0.4), inset 0 0 30px rgba(0, 240, 255, 0.1); }
        .dark .glow-green { box-shadow: 0 0 30px rgba(0, 255, 136, 0.4), inset 0 0 30px rgba(0, 255, 136, 0.1); }
        .dark .glow-red { box-shadow: 0 0 30px rgba(255, 51, 102, 0.4), inset 0 0 30px rgba(255, 51, 102, 0.1); }
        .dark .glow-amber { box-shadow: 0 0 30px rgba(255, 170, 0, 0.4), inset 0 0 30px rgba(255, 170, 0, 0.1); }

        .dark .text-glow-cyan { text-shadow: 0 0 20px rgba(0, 240, 255, 0.8), 0 0 40px rgba(0, 240, 255, 0.4); }
        .dark .text-glow-green { text-shadow: 0 0 20px rgba(0, 255, 136, 0.8), 0 0 40px rgba(0, 255, 136, 0.4); }
        .dark .text-glow-red { text-shadow: 0 0 20px rgba(255, 51, 102, 0.8), 0 0 40px rgba(255, 51, 102, 0.4); }
        .dark .text-glow-amber { text-shadow: 0 0 20px rgba(255, 170, 0, 0.8), 0 0 40px rgba(255, 170, 0, 0.4); }

        /* Light mode shadows */
        html:not(.dark) .glow-cyan { box-shadow: 0 4px 20px rgba(8, 145, 178, 0.15); }
        html:not(.dark) .glow-green { box-shadow: 0 4px 20px rgba(5, 150, 105, 0.15); }
        html:not(.dark) .glow-red { box-shadow: 0 4px 20px rgba(220, 38, 38, 0.15); }
        html:not(.dark) .glow-amber { box-shadow: 0 4px 20px rgba(217, 119, 6, 0.15); }

        html:not(.dark) .text-glow-cyan,
        html:not(.dark) .text-glow-green,
        html:not(.dark) .text-glow-red,
        html:not(.dark) .text-glow-amber { text-shadow: none; }

        /* Main status orb - Dark mode */
        .status-orb {
            position: relative;
            width: 280px; height: 280px;
            border-radius: 50%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            transition: all 0.5s ease;
        }

        .dark .status-orb.closed {
            background: radial-gradient(circle at 30% 30%, rgba(0, 255, 136, 0.3), rgba(0, 255, 136, 0.1) 50%, transparent 70%);
            border: 3px solid rgba(0, 255, 136, 0.6);
            box-shadow: 0 0 60px rgba(0, 255, 136, 0.4), 0 0 120px rgba(0, 255, 136, 0.2), inset 0 0 60px rgba(0, 255, 136, 0.1);
        }
        .dark .status-orb.open {
            background: radial-gradient(circle at 30% 30%, rgba(255, 51, 102, 0.3), rgba(255, 51, 102, 0.1) 50%, transparent 70%);
            border: 3px solid rgba(255, 51, 102, 0.6);
            box-shadow: 0 0 60px rgba(255, 51, 102, 0.4), 0 0 120px rgba(255, 51, 102, 0.2), inset 0 0 60px rgba(255, 51, 102, 0.1);
            animation: danger-pulse-dark 1s ease-in-out infinite;
        }
        .dark .status-orb.half_open {
            background: radial-gradient(circle at 30% 30%, rgba(255, 170, 0, 0.3), rgba(255, 170, 0, 0.1) 50%, transparent 70%);
            border: 3px solid rgba(255, 170, 0, 0.6);
            box-shadow: 0 0 60px rgba(255, 170, 0, 0.4), 0 0 120px rgba(255, 170, 0, 0.2), inset 0 0 60px rgba(255, 170, 0, 0.1);
            animation: warning-pulse-dark 2s ease-in-out infinite;
        }

        /* Light mode status orb */
        html:not(.dark) .status-orb.closed {
            background: radial-gradient(circle at 30% 30%, rgba(5, 150, 105, 0.15), rgba(5, 150, 105, 0.05) 50%, transparent 70%);
            border: 3px solid rgba(5, 150, 105, 0.5);
            box-shadow: 0 8px 40px rgba(5, 150, 105, 0.2);
        }
        html:not(.dark) .status-orb.open {
            background: radial-gradient(circle at 30% 30%, rgba(220, 38, 38, 0.15), rgba(220, 38, 38, 0.05) 50%, transparent 70%);
            border: 3px solid rgba(220, 38, 38, 0.5);
            box-shadow: 0 8px 40px rgba(220, 38, 38, 0.2);
            animation: danger-pulse-light 1s ease-in-out infinite;
        }
        html:not(.dark) .status-orb.half_open {
            background: radial-gradient(circle at 30% 30%, rgba(217, 119, 6, 0.15), rgba(217, 119, 6, 0.05) 50%, transparent 70%);
            border: 3px solid rgba(217, 119, 6, 0.5);
            box-shadow: 0 8px 40px rgba(217, 119, 6, 0.2);
            animation: warning-pulse-light 2s ease-in-out infinite;
        }

        @keyframes danger-pulse-dark {
            0%, 100% { box-shadow: 0 0 60px rgba(255, 51, 102, 0.4), 0 0 120px rgba(255, 51, 102, 0.2), inset 0 0 60px rgba(255, 51, 102, 0.1); }
            50% { box-shadow: 0 0 80px rgba(255, 51, 102, 0.6), 0 0 160px rgba(255, 51, 102, 0.3), inset 0 0 80px rgba(255, 51, 102, 0.2); }
        }
        @keyframes warning-pulse-dark {
            0%, 100% { box-shadow: 0 0 60px rgba(255, 170, 0, 0.4), 0 0 120px rgba(255, 170, 0, 0.2), inset 0 0 60px rgba(255, 170, 0, 0.1); }
            50% { box-shadow: 0 0 80px rgba(255, 170, 0, 0.5), 0 0 140px rgba(255, 170, 0, 0.25), inset 0 0 70px rgba(255, 170, 0, 0.15); }
        }
        @keyframes danger-pulse-light {
            0%, 100% { box-shadow: 0 8px 40px rgba(220, 38, 38, 0.2); }
            50% { box-shadow: 0 8px 50px rgba(220, 38, 38, 0.3); }
        }
        @keyframes warning-pulse-light {
            0%, 100% { box-shadow: 0 8px 40px rgba(217, 119, 6, 0.2); }
            50% { box-shadow: 0 8px 50px rgba(217, 119, 6, 0.3); }
        }

        /* Rotating ring */
        .rotating-ring {
            position: absolute;
            width: 320px; height: 320px;
            border-radius: 50%;
            animation: rotate 30s linear infinite;
        }
        .dark .rotating-ring { border: 1px dashed rgba(255, 255, 255, 0.1); }
        html:not(.dark) .rotating-ring { border: 1px dashed rgba(0, 0, 0, 0.1); }
        .rotating-ring::before {
            content: '';
            position: absolute;
            top: -4px; left: 50%;
            width: 8px; height: 8px;
            border-radius: 50%;
        }
        .dark .rotating-ring::before { background: rgba(0, 240, 255, 0.8); box-shadow: 0 0 10px rgba(0, 240, 255, 0.8); }
        html:not(.dark) .rotating-ring::before { background: #0891b2; box-shadow: 0 0 6px rgba(8, 145, 178, 0.5); }

        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* Panel styling */
        .dark .panel {
            background: linear-gradient(135deg, rgba(18, 18, 26, 0.9), rgba(13, 13, 20, 0.95));
            border: 1px solid rgba(0, 240, 255, 0.1);
            backdrop-filter: blur(10px);
        }
        html:not(.dark) .panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        /* Stat card */
        .stat-card { position: relative; overflow: hidden; }
        .dark .stat-card {
            background: linear-gradient(135deg, rgba(18, 18, 26, 0.8), rgba(13, 13, 20, 0.9));
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        html:not(.dark) .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--accent-color), transparent);
        }
        .dark .stat-card.cyan { --accent-color: rgba(0, 240, 255, 0.5); }
        .dark .stat-card.green { --accent-color: rgba(0, 255, 136, 0.5); }
        .dark .stat-card.red { --accent-color: rgba(255, 51, 102, 0.5); }
        .dark .stat-card.amber { --accent-color: rgba(255, 170, 0, 0.5); }
        html:not(.dark) .stat-card.cyan { --accent-color: rgba(8, 145, 178, 0.5); }
        html:not(.dark) .stat-card.green { --accent-color: rgba(5, 150, 105, 0.5); }
        html:not(.dark) .stat-card.red { --accent-color: rgba(220, 38, 38, 0.5); }
        html:not(.dark) .stat-card.amber { --accent-color: rgba(217, 119, 6, 0.5); }

        /* History item */
        .history-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: 8px;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
        }
        .dark .history-item { background: rgba(255, 255, 255, 0.02); }
        html:not(.dark) .history-item { background: rgba(0, 0, 0, 0.02); }
        .dark .history-item:hover { background: rgba(255, 255, 255, 0.05); }
        html:not(.dark) .history-item:hover { background: rgba(0, 0, 0, 0.04); }
        .dark .history-item.to-closed { border-left-color: #00ff88; }
        .dark .history-item.to-open { border-left-color: #ff3366; }
        .dark .history-item.to-half_open { border-left-color: #ffaa00; }
        html:not(.dark) .history-item.to-closed { border-left-color: #059669; }
        html:not(.dark) .history-item.to-open { border-left-color: #dc2626; }
        html:not(.dark) .history-item.to-half_open { border-left-color: #d97706; }

        /* Alert banner */
        .alert-banner { animation: alert-slide 0.5s ease-out; }
        @keyframes alert-slide {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Status indicator dot */
        .status-indicator { width: 10px; height: 10px; border-radius: 50%; animation: blink 1.5s ease-in-out infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

        /* State transitions */
        .state-transition { transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1); }

        /* Theme toggle button */
        .theme-toggle {
            position: relative;
            width: 56px; height: 28px;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .dark .theme-toggle { background: #1a1a24; border: 1px solid rgba(0, 240, 255, 0.3); }
        html:not(.dark) .theme-toggle { background: #e2e8f0; border: 1px solid #cbd5e1; }
        .theme-toggle::after {
            content: '';
            position: absolute;
            top: 3px;
            width: 20px; height: 20px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        .dark .theme-toggle::after { left: 3px; background: #00f0ff; box-shadow: 0 0 8px #00f0ff; }
        html:not(.dark) .theme-toggle::after { left: 31px; background: #f59e0b; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }

        /* Theme-aware text colors */
        .dark .text-theme-primary { color: #00f0ff; }
        html:not(.dark) .text-theme-primary { color: #0891b2; }
        .dark .text-theme-muted { color: rgba(255, 255, 255, 0.4); }
        html:not(.dark) .text-theme-muted { color: #64748b; }
        .dark .text-theme-subtle { color: rgba(255, 255, 255, 0.6); }
        html:not(.dark) .text-theme-subtle { color: #475569; }
        .dark .text-theme-body { color: #ffffff; }
        html:not(.dark) .text-theme-body { color: #0f172a; }
        .dark .border-theme { border-color: rgba(0, 240, 255, 0.2); }
        html:not(.dark) .border-theme { border-color: #e2e8f0; }
        .dark .bg-theme-header { background: rgba(13, 13, 20, 0.8); }
        html:not(.dark) .bg-theme-header { background: rgba(255, 255, 255, 0.9); }

        /* Service badge */
        .service-badge {
            display: flex; align-items: center; gap: 6px;
            padding: 4px 12px; border-radius: 8px;
            font-size: 13px; font-family: 'Geist Mono', monospace;
            cursor: pointer; transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .dark .service-badge { background: rgba(255, 255, 255, 0.03); }
        html:not(.dark) .service-badge { background: rgba(0, 0, 0, 0.02); }
        .dark .service-badge:hover { background: rgba(255, 255, 255, 0.06); }
        html:not(.dark) .service-badge:hover { background: rgba(0, 0, 0, 0.04); }
        .dark .service-badge.selected { background: rgba(0, 240, 255, 0.08); border-color: rgba(0, 240, 255, 0.3); }
        html:not(.dark) .service-badge.selected { background: rgba(8, 145, 178, 0.06); border-color: rgba(8, 145, 178, 0.3); }
        .service-badge .dot { width: 8px; height: 8px; border-radius: 50%; }

        /* Service selector (dropdown) */
        .dark .service-select {
            background: #1a1a24;
            border: 1px solid rgba(0, 240, 255, 0.2);
            color: #ffffff;
        }
        html:not(.dark) .service-select {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #0f172a;
        }
    </style>
    <script>
        // Apply theme immediately to prevent flash
        (function() {
            const urlParams = new URLSearchParams(window.location.search);
            const urlTheme = urlParams.get('theme');
            const savedTheme = localStorage.getItem('fuse-theme');
            const theme = urlTheme || savedTheme || 'dark';
            if (theme === 'light') {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
</head>
<body class="grid-bg scanlines">
    <!-- Header Bar -->
    <header class="border-b border-theme bg-theme-header backdrop-blur-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <div class="status-indicator dark:bg-neon-cyan bg-day-cyan"></div>
                    <span class="font-mono dark:text-neon-cyan text-day-cyan text-sm tracking-wider">LIVE MONITORING</span>
                </div>
            </div>
            <h1 class="font-display font-bold text-2xl tracking-tight">
                <span class="text-theme-primary text-glow-cyan">FUSE</span>
                <span class="text-theme-muted font-mono text-lg ml-2">//</span>
                <span class="text-theme-subtle font-light">STATUS</span>
            </h1>
            <div class="flex items-center gap-4">
                <select id="service-selector" class="service-select rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none">
                    <option value="">No services</option>
                </select>
                <button onclick="toggleTheme()" class="theme-toggle" title="Toggle light/dark mode" aria-label="Toggle theme"></button>
                <span class="font-mono text-xs text-theme-muted">UPDATED:</span>
                <span id="last-updated" class="font-mono text-sm dark:text-neon-cyan text-day-cyan">--:--:--</span>
            </div>
        </div>
    </header>

    <!-- Services Overview Bar -->
    <div id="services-bar" class="border-b border-theme bg-theme-header" style="display:none;">
        <div class="max-w-7xl mx-auto px-6 py-2.5 flex items-center gap-3 flex-wrap">
            <span class="font-mono text-xs text-theme-muted uppercase tracking-wider mr-1">Services:</span>
            <div id="services-badges" class="flex items-center gap-2 flex-wrap"></div>
        </div>
    </div>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <!-- Empty State -->
        <div id="empty-state" style="display:none;" class="text-center py-20">
            <svg class="w-16 h-16 mx-auto mb-4 text-theme-muted opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            <h2 class="font-display text-2xl font-semibold mb-2 text-theme-subtle">No Services Configured</h2>
            <p class="text-theme-muted max-w-md mx-auto">
                Add services to your <code class="dark:text-neon-cyan text-day-cyan">config/fuse.php</code> services array to monitor circuit breaker states.
            </p>
        </div>

        <!-- Main Content -->
        <div id="main-content" style="display:none;">
            <!-- Main Status Display -->
            <section class="flex flex-col lg:flex-row items-center justify-center gap-12 mb-12">
                <!-- Status Orb -->
                <div class="relative flex items-center justify-center">
                    <div class="rotating-ring"></div>
                    <div id="status-orb" class="status-orb closed state-transition">
                        <div id="status-icon" class="text-7xl mb-2 state-transition">
                            <svg class="w-20 h-20 dark:text-neon-green text-day-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div id="status-text" class="font-display font-extrabold text-4xl tracking-tight uppercase state-transition dark:text-neon-green text-day-green text-glow-green">
                            CLOSED
                        </div>
                        <div id="status-service-label" class="font-mono text-xs text-theme-muted mt-2">CIRCUIT STATE</div>
                    </div>
                </div>

                <!-- State History Panel -->
                <div class="panel rounded-2xl p-6 w-full max-w-md">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-display font-semibold text-lg flex items-center gap-2 text-theme-body">
                            <svg class="w-5 h-5 dark:text-neon-cyan text-day-cyan" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            State History
                        </h3>
                        <span class="font-mono text-xs text-theme-muted">RECENT TRANSITIONS</span>
                    </div>
                    <div id="state-history" class="space-y-2 max-h-64 overflow-y-auto pr-2">
                        <div class="text-theme-muted font-mono text-sm text-center py-8">
                            <svg class="w-8 h-8 mx-auto mb-2 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            Watching for state changes...
                        </div>
                    </div>
                </div>
            </section>

            <!-- Alert Banners -->
            <div id="alert-container" class="mb-8"></div>

            <!-- Stats Grid -->
            <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="stat-card cyan rounded-xl p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-mono text-xs text-theme-muted uppercase tracking-wider">Attempts</span>
                        <svg class="w-4 h-4 dark:text-neon-cyan/50 text-day-cyan/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                    </div>
                    <div id="stat-attempts" class="font-display font-bold text-5xl dark:text-neon-cyan text-day-cyan text-glow-cyan">0</div>
                    <div class="font-mono text-xs text-theme-muted mt-1">in current window</div>
                </div>

                <div class="stat-card red rounded-xl p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-mono text-xs text-theme-muted uppercase tracking-wider">Failures</span>
                        <svg class="w-4 h-4 dark:text-neon-red/50 text-day-red/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div id="stat-failures" class="font-display font-bold text-5xl dark:text-neon-red text-day-red text-glow-red">0</div>
                    <div class="font-mono text-xs text-theme-muted mt-1">in current window</div>
                </div>

                <div id="stat-rate-card" class="stat-card green rounded-xl p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-mono text-xs text-theme-muted uppercase tracking-wider">Failure Rate</span>
                        <svg id="stat-rate-icon" class="w-4 h-4 dark:text-neon-green/50 text-day-green/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <div id="stat-rate" class="font-display font-bold text-5xl dark:text-neon-green text-day-green text-glow-green">0%</div>
                    <div id="stat-rate-desc" class="font-mono text-xs text-theme-muted mt-1">threshold: --%</div>
                </div>

                <div class="stat-card amber rounded-xl p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-mono text-xs text-theme-muted uppercase tracking-wider">Min Requests</span>
                        <svg class="w-4 h-4 dark:text-neon-amber/50 text-day-amber/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <div id="stat-min-requests" class="font-display font-bold text-5xl dark:text-neon-amber text-day-amber text-glow-amber">0</div>
                    <div class="font-mono text-xs text-theme-muted mt-1">before evaluation</div>
                </div>
            </section>

            <!-- System Status Bar -->
            <section class="panel rounded-xl p-4 mb-8">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-6">
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-xs text-theme-muted uppercase">Protection</span>
                            <span id="breaker-status" class="font-display font-semibold px-3 py-1 rounded-full text-sm dark:bg-neon-cyan/20 dark:text-neon-cyan bg-day-cyan/10 text-day-cyan">ENABLED</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-6 font-mono text-sm">
                        <div>
                            <span class="text-theme-muted">Timeout:</span>
                            <span id="config-timeout" class="text-theme-body ml-1">--s</span>
                        </div>
                        <div>
                            <span class="text-theme-muted">Threshold:</span>
                            <span id="config-threshold" class="text-theme-body ml-1">--%</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <!-- Footer -->
    <footer class="border-t border-theme mt-12 py-6">
        <div class="max-w-7xl mx-auto px-6 flex items-center justify-between text-theme-muted font-mono text-xs">
            <span>FUSE CIRCUIT BREAKER MONITOR</span>
            <span>REFRESH: {{ $pollingInterval }}s</span>
        </div>
    </footer>

    <script>
        const DATA_URL = document.querySelector('meta[name="fuse-data-url"]').content;
        const POLL_INTERVAL = {{ $pollingInterval }} * 1000;
        const initialData = @json($initialData);

        let selectedService = null;
        let servicesData = {};
        let previousStates = {};
        let lastRenderedService = null;

        function toggleTheme() {
            const html = document.documentElement;
            const isDark = html.classList.contains('dark');
            if (isDark) {
                html.classList.remove('dark');
                localStorage.setItem('fuse-theme', 'light');
            } else {
                html.classList.add('dark');
                localStorage.setItem('fuse-theme', 'dark');
            }
        }

        const stateColors = {
            'closed': { text: 'dark:text-neon-green text-day-green text-glow-green', icon: 'dark:text-neon-green text-day-green' },
            'open': { text: 'dark:text-neon-red text-day-red text-glow-red', icon: 'dark:text-neon-red text-day-red' },
            'half_open': { text: 'dark:text-neon-amber text-day-amber text-glow-amber', icon: 'dark:text-neon-amber text-day-amber' }
        };

        const stateIcons = {
            'closed': `<svg class="w-20 h-20 dark:text-neon-green text-day-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>`,
            'open': `<svg class="w-20 h-20 dark:text-neon-red text-day-red" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>`,
            'half_open': `<svg class="w-20 h-20 dark:text-neon-amber text-day-amber" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>`
        };

        function renderServiceBadges(services) {
            const container = document.getElementById('services-badges');
            container.innerHTML = '';
            Object.entries(services).forEach(([name, data]) => {
                const badge = document.createElement('button');
                badge.className = 'service-badge' + (name === selectedService ? ' selected' : '');

                let dotColor;
                if (data.state === 'closed') dotColor = 'background:#00ff88;';
                else if (data.state === 'open') dotColor = 'background:#ff3366;';
                else dotColor = 'background:#ffaa00;';

                // In light mode, use day colors
                const isDark = document.documentElement.classList.contains('dark');
                if (!isDark) {
                    if (data.state === 'closed') dotColor = 'background:#059669;';
                    else if (data.state === 'open') dotColor = 'background:#dc2626;';
                    else dotColor = 'background:#d97706;';
                }

                badge.innerHTML = `<span class="dot" style="${dotColor}"></span><span>${name}</span>`;
                badge.addEventListener('click', () => {
                    selectedService = name;
                    document.getElementById('service-selector').value = name;
                    renderSelected();
                });
                container.appendChild(badge);
            });
        }

        function renderHistory(history) {
            const container = document.getElementById('state-history');
            if (!history || history.length === 0) {
                container.innerHTML = `
                    <div class="text-theme-muted font-mono text-sm text-center py-8">
                        <svg class="w-8 h-8 mx-auto mb-2 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        Watching for state changes...
                    </div>
                `;
                return;
            }

            container.innerHTML = history.slice().reverse().map(entry => `
                <div class="history-item to-${entry.to}">
                    <span class="font-mono text-xs text-theme-muted">${entry.time}</span>
                    <span class="${stateColors[entry.from]?.text || 'text-theme-muted'} font-mono text-sm">${entry.from.toUpperCase().replace('_', '-')}</span>
                    <svg class="w-4 h-4 text-theme-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                    </svg>
                    <span class="${stateColors[entry.to]?.text || 'text-theme-muted'} font-mono text-sm font-semibold">${entry.to.toUpperCase().replace('_', '-')}</span>
                </div>
            `).join('');
        }

        function updateAlertBanner(state, openedAt, recoveryAt, stateChanged) {
            const container = document.getElementById('alert-container');

            if (stateChanged) {
                if (state === 'open') {
                    container.innerHTML = `
                        <div class="alert-banner dark:bg-gradient-to-r dark:from-neon-red/20 dark:to-transparent bg-gradient-to-r from-day-red/10 to-transparent border dark:border-neon-red/30 border-day-red/30 rounded-xl p-6 mb-4">
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0">
                                    <svg class="w-8 h-8 dark:text-neon-red text-day-red" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-display font-bold text-xl dark:text-neon-red text-day-red mb-1">Circuit Open - Protection Active</h4>
                                    <p class="text-theme-muted text-sm mb-3">Jobs are being released back to the queue to prevent cascade failures.</p>
                                    <div class="grid grid-cols-2 gap-4 font-mono text-sm">
                                        <div>
                                            <span class="text-theme-muted">Opened At:</span>
                                            <span id="alert-opened-at" class="text-theme-body ml-2">${openedAt || '-'}</span>
                                        </div>
                                        <div>
                                            <span class="text-theme-muted">Recovery Test:</span>
                                            <span id="alert-recovery-at" class="dark:text-neon-amber text-day-amber ml-2">${recoveryAt || '-'}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                } else if (state === 'half_open') {
                    container.innerHTML = `
                        <div class="alert-banner dark:bg-gradient-to-r dark:from-neon-amber/20 dark:to-transparent bg-gradient-to-r from-day-amber/10 to-transparent border dark:border-neon-amber/30 border-day-amber/30 rounded-xl p-6 mb-4">
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0">
                                    <svg class="w-8 h-8 dark:text-neon-amber text-day-amber animate-spin" style="animation-duration: 3s;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-display font-bold text-xl dark:text-neon-amber text-day-amber mb-1">Testing Recovery</h4>
                                    <p class="text-theme-muted text-sm">A single probe request is testing if the service has recovered. Success closes the circuit, failure re-opens it.</p>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    container.innerHTML = '';
                }
            } else if (state === 'open') {
                const openedAtEl = document.getElementById('alert-opened-at');
                const recoveryAtEl = document.getElementById('alert-recovery-at');
                if (openedAtEl) openedAtEl.textContent = openedAt || '-';
                if (recoveryAtEl) recoveryAtEl.textContent = recoveryAt || '-';
            }
        }

        function renderSelected() {
            const data = servicesData[selectedService];
            if (!data) return;

            const state = data.state;
            const colors = stateColors[state] || stateColors.closed;

            // Orb
            const orb = document.getElementById('status-orb');
            orb.classList.remove('closed', 'open', 'half_open');
            orb.classList.add(state);

            document.getElementById('status-icon').innerHTML = stateIcons[state];

            const text = document.getElementById('status-text');
            text.className = `font-display font-extrabold text-4xl tracking-tight uppercase state-transition ${colors.text}`;
            text.textContent = state.toUpperCase().replace('_', '-');

            document.getElementById('status-service-label').textContent = selectedService.toUpperCase();

            // History
            renderHistory(data.state_history);

            // Alert
            const serviceChanged = lastRenderedService !== selectedService;
            const prevState = previousStates[selectedService];
            const stateChanged = serviceChanged || prevState === undefined || prevState !== state;
            previousStates[selectedService] = state;
            lastRenderedService = selectedService;

            updateAlertBanner(state,
                data.opened_at ? new Date(data.opened_at * 1000).toLocaleTimeString() : null,
                data.recovery_at ? new Date(data.recovery_at * 1000).toLocaleTimeString() : null,
                stateChanged
            );

            // Stats
            document.getElementById('stat-attempts').textContent = data.attempts;
            document.getElementById('stat-failures').textContent = data.failures;

            const rateEl = document.getElementById('stat-rate');
            const rateCard = document.getElementById('stat-rate-card');
            const rateIcon = document.getElementById('stat-rate-icon');
            rateEl.textContent = data.failure_rate + '%';

            if (data.failure_rate >= 50) {
                rateEl.className = 'font-display font-bold text-5xl dark:text-neon-red text-day-red text-glow-red';
                rateCard.classList.remove('green');
                rateCard.classList.add('red');
                rateIcon.className = 'w-4 h-4 dark:text-neon-red/50 text-day-red/50';
            } else {
                rateEl.className = 'font-display font-bold text-5xl dark:text-neon-green text-day-green text-glow-green';
                rateCard.classList.remove('red');
                rateCard.classList.add('green');
                rateIcon.className = 'w-4 h-4 dark:text-neon-green/50 text-day-green/50';
            }

            document.getElementById('stat-rate-desc').textContent = 'threshold: ' + data.threshold + '%';
            document.getElementById('stat-min-requests').textContent = data.min_requests;
            document.getElementById('config-timeout').textContent = data.timeout + 's';
            document.getElementById('config-threshold').textContent = data.threshold + '%';

            // Re-render badges for selection
            renderServiceBadges(servicesData);
        }

        function render(services, enabled, timestamp) {
            servicesData = services;
            const serviceNames = Object.keys(services);

            document.getElementById('last-updated').textContent = timestamp || '--:--:--';

            // Protection badge
            const breakerStatus = document.getElementById('breaker-status');
            breakerStatus.textContent = enabled ? 'ENABLED' : 'DISABLED';
            breakerStatus.className = `font-display font-semibold px-3 py-1 rounded-full text-sm ${
                enabled ? 'dark:bg-neon-cyan/20 dark:text-neon-cyan bg-day-cyan/10 text-day-cyan' : 'dark:bg-neon-red/20 dark:text-neon-red bg-day-red/10 text-day-red'
            }`;

            if (serviceNames.length === 0) {
                document.getElementById('empty-state').style.display = '';
                document.getElementById('main-content').style.display = 'none';
                document.getElementById('services-bar').style.display = 'none';
                return;
            }

            document.getElementById('empty-state').style.display = 'none';
            document.getElementById('main-content').style.display = '';
            document.getElementById('services-bar').style.display = '';

            // Populate selector
            const selector = document.getElementById('service-selector');
            selector.innerHTML = serviceNames.map(n => `<option value="${n}">${n}</option>`).join('');

            if (!selectedService || !services[selectedService]) {
                selectedService = serviceNames[0];
            }
            selector.value = selectedService;

            renderServiceBadges(services);
            renderSelected();
        }

        // Selector change
        document.getElementById('service-selector').addEventListener('change', function() {
            selectedService = this.value;
            renderSelected();
        });

        // Initial render
        render(initialData, true, new Date().toLocaleTimeString('en-GB', { hour12: false }));

        // Polling
        setInterval(async () => {
            try {
                const response = await fetch(DATA_URL);
                if (!response.ok) return;
                const data = await response.json();
                render(data.services, data.circuit_breaker_enabled, data.timestamp);
            } catch (error) {
                // Silently ignore polling errors
            }
        }, POLL_INTERVAL);
    </script>
</body>
</html>
