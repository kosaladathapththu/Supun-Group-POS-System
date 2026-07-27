<?php
session_start();
include "db.php";

$error = "";
$show_choice = false;
$choice_name = "";

/* ── If admin already authenticated, handle destination choice ── */
if (isset($_POST["go_to"]) && isset($_SESSION["pending_admin_id"])) {
    $dest = $_POST["go_to"];

    /* Load the pending admin into the real session */
    $uid = (int) $_SESSION["pending_admin_id"];
    $row = $conn
        ->query("SELECT * FROM users WHERE user_id = $uid LIMIT 1")
        ->fetch_assoc();

    $_SESSION["user_id"] = $row["user_id"];
    $_SESSION["full_name"] = $row["full_name"];
    $_SESSION["role"] = $row["role"];
    unset($_SESSION["pending_admin_id"], $_SESSION["pending_admin_name"]);

    if ($dest === "dashboard") {
        header("Location: dashboard.php");
    } else {
        header("Location: pos.php");
    }
    exit();
}

/* ── Regular login POST ── */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST["go_to"])) {
    $username = trim($_POST["username"] ?? "");
    $password = trim($_POST["password"] ?? "");
    $username_safe = $conn->real_escape_string($username);

    $sql = "SELECT * FROM users WHERE username = '$username_safe' AND status = 1 LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user["password"])) {
            if ($user["role"] === "manager") {
                $user["role"] = "accountant";
            }
            if (in_array($user["role"], ["admin", "accountant"], true)) {
                /* Store temporarily — show destination choice */
                $_SESSION["pending_admin_id"] = $user["user_id"];
                $_SESSION["pending_admin_name"] = $user["full_name"];
                $show_choice = true;
                $choice_name = $user["full_name"];
            } else {
                /* Cashier — go straight to POS */
                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["role"] = $user["role"];
                header("Location: pos.php");
                exit();
            }
        } else {
            $error = "Incorrect password. Please try again.";
        }
    } else {
        $error = "No active account found for that username.";
    }
}

/* ── If returning to choice screen (pending session exists) ── */
if (!$show_choice && isset($_SESSION["pending_admin_id"])) {
    $show_choice = true;
    $choice_name = $_SESSION["pending_admin_name"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ST Pvt Ltd. — Login</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Lora:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --primary:    #0f766e;
    --primary-dk: #b84a1f;
    --primary-lt: #fef3ed;
    --bg:         #f2f4f8;
    --white:      #ffffff;
    --border:     #dde0ea;
    --text:       #1c2038;
    --text-mid:   #454a66;
    --text-muted: #8e94b0;
    --red:        #dc2626;
    --red-lt:     #fef2f2;
    --green:      #15803d;
    --green-lt:   #f0fdf4;
    --shadow-md:  0 4px 24px rgba(0,0,0,.10);
    --shadow-lg:  0 12px 48px rgba(0,0,0,.14);
    --radius:     16px;
    --radius-sm:  10px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Nunito', sans-serif;
    background: var(--bg);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    position: relative;
    overflow: hidden;
}

/* Subtle background pattern */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 80% 60% at 20% 10%, rgba(217,92,43,.08) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 80% 90%, rgba(217,92,43,.06) 0%, transparent 60%);
    pointer-events: none;
}

/* Dot grid */
body::after {
    content: '';
    position: fixed;
    inset: 0;
    background-image: radial-gradient(circle, rgba(0,0,0,.06) 1px, transparent 1px);
    background-size: 28px 28px;
    pointer-events: none;
}

/* ── CARD ── */
.card {
    background: var(--white);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    width: 100%;
    max-width: 420px;
    position: relative;
    z-index: 1;
    animation: cardIn .4s cubic-bezier(.22,1,.36,1) both;
}

@keyframes cardIn {
    from { opacity: 0; transform: translateY(22px) scale(.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* ── CARD TOP STRIP ── */
.card-top {
    background: var(--primary);
    border-radius: calc(var(--radius) - 1px) calc(var(--radius) - 1px) 0 0;
    padding: 26px 28px 22px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.card-top::before {
    content: '';
    position: absolute; inset: 0;
    background: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 18px,
        rgba(255,255,255,.04) 18px,
        rgba(255,255,255,.04) 36px
    );
}

.brand-logo {
    width: 54px; height: 54px;
    background: rgba(255,255,255,.2);
    border: 2px solid rgba(255,255,255,.35);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; color: #fff;
    margin: 0 auto 12px;
    position: relative;
}

.card-top h1 {
    font-family: 'Lora', serif;
    font-size: 22px; font-weight: 700;
    color: #fff;
    line-height: 1.1;
    margin-bottom: 3px;
}

.card-top small {
    font-size: 11px;
    color: rgba(255,255,255,.75);
    text-transform: uppercase;
    letter-spacing: .14em;
    font-weight: 700;
}

/* ── CARD BODY ── */
.card-body {
    padding: 26px 28px 28px;
}

/* ── SCREENS ── */
.screen { display: none; }
.screen.active { display: block; animation: fadeUp .3s ease both; }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── SECTION HEADING ── */
.screen-label {
    font-size: 15px; font-weight: 900;
    color: var(--text); margin-bottom: 18px;
    display: flex; align-items: center; gap: 8px;
}
.screen-label i { color: var(--primary); font-size: 14px; }

/* ── FORM FIELDS ── */
.field { margin-bottom: 14px; }

.field label {
    display: block;
    font-size: 11px; font-weight: 900;
    text-transform: uppercase; letter-spacing: .09em;
    color: var(--text-mid); margin-bottom: 5px;
}

.inp-wrap { position: relative; }

.inp-wrap i {
    position: absolute; left: 13px; top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted); font-size: 13px;
    pointer-events: none;
}

.inp {
    background: var(--bg);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 12px 13px 12px 38px;
    font-size: 14px; font-weight: 700;
    font-family: 'Nunito', sans-serif;
    color: var(--text); width: 100%;
    outline: none;
    transition: border-color .16s, box-shadow .16s;
}
.inp::placeholder { color: var(--text-muted); font-weight: 600; }
.inp:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(217,92,43,.12);
    background: var(--white);
}

/* Password toggle */
.toggle-pw {
    position: absolute; right: 12px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    color: var(--text-muted); font-size: 14px;
    cursor: pointer; padding: 2px;
    transition: color .14s;
}
.toggle-pw:hover { color: var(--primary); }

/* ── ERROR ── */
.error-box {
    background: var(--red-lt);
    border: 1.5px solid #fca5a5;
    border-radius: var(--radius-sm);
    padding: 10px 13px;
    font-size: 13px; font-weight: 800;
    color: var(--red);
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 16px;
    animation: shake .3s ease;
}

@keyframes shake {
    0%,100% { transform: translateX(0); }
    25%      { transform: translateX(-5px); }
    75%      { transform: translateX(5px); }
}

/* ── LOGIN BUTTON ── */
.btn-login {
    width: 100%;
    padding: 13px;
    background: var(--primary); color: #fff;
    border: none; border-radius: var(--radius-sm);
    font-size: 15px; font-weight: 900;
    font-family: 'Nunito', sans-serif;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 4px 14px rgba(217,92,43,.35);
    transition: all .18s;
    letter-spacing: .02em;
}
.btn-login:hover {
    background: var(--primary-dk);
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(217,92,43,.42);
}
.btn-login:active { transform: translateY(0); }

/* ── CHOICE SCREEN ── */
.welcome-msg {
    text-align: center;
    margin-bottom: 20px;
}

.welcome-msg .avatar {
    width: 52px; height: 52px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 900; color: #fff;
    margin: 0 auto 10px;
}

.welcome-msg h3 {
    font-size: 16px; font-weight: 900;
    color: var(--text); margin-bottom: 3px;
}

.welcome-msg p {
    font-size: 13px; color: var(--text-muted); font-weight: 600;
}

/* Choice cards */
.choice-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
}

.choice-card {
    background: var(--bg);
    border: 2px solid var(--border);
    border-radius: var(--radius);
    padding: 20px 14px;
    text-align: center;
    cursor: pointer;
    text-decoration: none;
    transition: all .18s;
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    position: relative;
    overflow: hidden;
}

.choice-card::before {
    content: '';
    position: absolute; inset: 0;
    background: var(--primary);
    opacity: 0;
    transition: opacity .18s;
    border-radius: calc(var(--radius) - 1px);
}

.choice-card:hover {
    border-color: var(--primary);
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.cc-icon {
    width: 52px; height: 52px;
    border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    position: relative; z-index: 1;
    transition: all .18s;
}

.cc-pos  { background: #fff3ec; color: var(--primary); border: 1.5px solid #fbd0bc; }
.cc-dash { background: #eef2ff; color: #4f46e5;        border: 1.5px solid #c7d2fe; }

.choice-card:hover .cc-pos  { background: var(--primary); color: #fff; border-color: transparent; }
.choice-card:hover .cc-dash { background: #4f46e5; color: #fff; border-color: transparent; }

.cc-title {
    font-size: 14px; font-weight: 900;
    color: var(--text); position: relative; z-index: 1;
    transition: color .18s;
}

.cc-sub {
    font-size: 11px; font-weight: 700;
    color: var(--text-muted); position: relative; z-index: 1;
    line-height: 1.4;
}

.choice-card:hover .cc-title { color: var(--primary); }
.choice-card:hover .cc-sub   { color: var(--primary-dk); }

/* Submit choice via form */
.choice-btn-wrap { display: contents; }

/* Back link */
.back-link {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    font-size: 12px; font-weight: 800;
    color: var(--text-muted);
    text-decoration: none;
    cursor: pointer;
    background: none; border: none;
    font-family: 'Nunito', sans-serif;
    width: 100%;
    transition: color .15s;
    padding: 4px;
}
.back-link:hover { color: var(--primary); }

/* ── FOOTER NOTE ── */
.card-footer {
    padding: 13px 28px 16px;
    border-top: 1px solid var(--border);
    text-align: center;
}

.card-footer p {
    font-size: 11px; font-weight: 700;
    color: var(--text-muted);
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
</style>
</head>
<body>

<div class="card">

    <!-- Top Strip -->
    <div class="card-top">
        <div class="brand-logo"><i class="fa-solid fa-store"></i></div>
        <h1>ST Pvt Ltd.</h1>
        <p>Supun Traders Private Limited</p>
        <small>Retail &amp; Wholesale Management</small>
    </div>

    <!-- Card Body -->
    <div class="card-body">

        <!-- ══ LOGIN SCREEN ══ -->
        <div class="screen <?php echo !$show_choice
            ? "active"
            : ""; ?>" id="loginScreen">

            <div class="screen-label">
                <i class="fa-solid fa-right-to-bracket"></i> Sign In to Your Account
            </div>

            <?php if (!empty($error)): ?>
            <div class="error-box">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <div class="field">
                    <label>Username</label>
                    <div class="inp-wrap">
                        <i class="fa-solid fa-user"></i>
                        <input type="text" name="username" class="inp"
                               placeholder="Enter your username" required
                               value="<?php echo htmlspecialchars(
                                   $_POST["username"] ?? "",
                               ); ?>"
                               autocomplete="username">
                    </div>
                </div>

                <div class="field">
                    <label>Password</label>
                    <div class="inp-wrap">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" name="password" id="pwField" class="inp"
                               placeholder="Enter your password" required
                               autocomplete="current-password">
                        <button type="button" class="toggle-pw" onclick="togglePw()" id="pwToggle" tabindex="-1">
                            <i class="fa-solid fa-eye" id="pwIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fa-solid fa-right-to-bracket"></i> Login
                </button>
            </form>
        </div>

        <!-- ══ CHOICE SCREEN (admin only) ══ -->
        <div class="screen <?php echo $show_choice
            ? "active"
            : ""; ?>" id="choiceScreen">

            <div class="welcome-msg">
                <div class="avatar">
                    <?php echo strtoupper(
                        substr(
                            $choice_name ?:
                            $_SESSION["pending_admin_name"] ?? "A",
                            0,
                            1,
                        ),
                    ); ?>
                </div>
                <h3>Welcome, <?php echo htmlspecialchars(
                    $choice_name ?: $_SESSION["pending_admin_name"] ?? "",
                ); ?>!</h3>
                <p>You have management access. Where would you like to go?</p>
            </div>

            <div class="choice-grid">

                <!-- POS -->
                <form method="POST">
                    <input type="hidden" name="go_to" value="pos">
                    <button type="submit" class="choice-card" style="width:100%;">
                        <div class="cc-icon cc-pos">
                            <i class="fa-solid fa-cash-register"></i>
                        </div>
                        <div>
                            <div class="cc-title">POS Terminal</div>
                            <div class="cc-sub">Create retail and wholesale sales</div>
                        </div>
                    </button>
                </form>

                <!-- Dashboard -->
                <form method="POST">
                    <input type="hidden" name="go_to" value="dashboard">
                    <button type="submit" class="choice-card" style="width:100%;">
                        <div class="cc-icon cc-dash">
                            <i class="fa-solid fa-gauge-high"></i>
                        </div>
                        <div>
                            <div class="cc-title">Management Dashboard</div>
                            <div class="cc-sub">Reports, accounts and management</div>
                        </div>
                    </button>
                </form>

            </div>

            <!-- Back to login -->
            <form method="POST" action="logout.php" style="text-align:center;">
                <button type="submit" class="back-link">
                    <i class="fa-solid fa-arrow-left"></i> Sign in as a different user
                </button>
            </form>

        </div>

    </div><!-- /card-body -->

    <div class="card-footer">
        <p><i class="fa-solid fa-shield-halved"></i> Secure login — ST Pvt Ltd. POS System</p>
    </div>

</div>

<script>
function togglePw() {
    const f = document.getElementById('pwField');
    const i = document.getElementById('pwIcon');
    if (f.type === 'password') {
        f.type = 'text';
        i.className = 'fa-solid fa-eye-slash';
    } else {
        f.type = 'password';
        i.className = 'fa-solid fa-eye';
    }
}
</script>
</body>
</html>
