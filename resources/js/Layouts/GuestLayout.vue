<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="auth-page">
        <!-- Left Panel - Branding -->
        <div class="auth-branding">
            <div class="branding-content">
                <Link href="/" class="brand-logo">TECHNICIAN WORLD</Link>
                <h1>Professional Technical Services</h1>
                <p>Connect with qualified technicians across Kenya. Quality work, reliable service, transparent pricing.</p>
                <div class="branding-features">
                    <div class="feature-item">
                        <i class="fas fa-check-circle"></i>
                        <span>100+ Vetted Technicians</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Real-Time Project Tracking</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Transparent Pricing</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Panel - Form -->
        <div class="auth-form-container">
            <div class="auth-form-wrapper">
                <div class="auth-form-header">
                    <Link href="/" class="mobile-logo">TECHNICIAN WORLD</Link>
                    <h2>Welcome Back</h2>
                    <p>Sign in to access your dashboard</p>
                </div>
                <div class="auth-form-content">
                    <slot />
                </div>
                <div class="auth-form-footer">
                    <p>Don't have an account? <Link :href="route('register')" class="auth-link">Sign Up</Link></p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.auth-page {
    display: flex;
    min-height: 100vh;
}

/* Left Branding Panel */
.auth-branding {
    flex: 1;
    background: linear-gradient(135deg, #00334b 0%, #053272 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 3rem;
    position: relative;
    overflow: hidden;
}

.auth-branding::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 60%);
    animation: pulse 15s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 0.5; }
    50% { transform: scale(1.1); opacity: 0.3; }
}

.branding-content {
    position: relative;
    z-index: 1;
    max-width: 450px;
}

.brand-logo {
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: 2px;
    color: white;
    text-decoration: none;
    display: inline-block;
    margin-bottom: 2rem;
}

.branding-content h1 {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 1rem;
    line-height: 1.2;
}

.branding-content p {
    font-size: 1.1rem;
    opacity: 0.9;
    line-height: 1.6;
    margin-bottom: 2rem;
}

.branding-features {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.feature-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1rem;
}

.feature-item i {
    color: #16A34A;
    font-size: 1.2rem;
}

/* Right Form Panel */
.auth-form-container {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
    background-color: #f8f9fa;
}

.auth-form-wrapper {
    width: 100%;
    max-width: 420px;
}

.auth-form-header {
    text-align: center;
    margin-bottom: 2rem;
}

.mobile-logo {
    display: none;
    font-size: 1.25rem;
    font-weight: 700;
    letter-spacing: 2px;
    color: #053272;
    text-decoration: none;
    margin-bottom: 1.5rem;
}

.auth-form-header h2 {
    font-size: 1.75rem;
    font-weight: 700;
    color: #212529;
    margin-bottom: 0.5rem;
}

.auth-form-header p {
    color: #6c757d;
    font-size: 1rem;
}

.auth-form-content {
    background: white;
    padding: 2rem;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
}

.auth-form-content :deep(label) {
    font-weight: 600;
    color: #212529;
    font-size: 0.9rem;
}

.auth-form-content :deep(input[type="email"]),
.auth-form-content :deep(input[type="password"]),
.auth-form-content :deep(input[type="text"]) {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    font-size: 1rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.auth-form-content :deep(input:focus) {
    outline: none;
    border-color: #053272;
    box-shadow: 0 0 0 3px rgba(5, 50, 114, 0.1);
}

.auth-form-content :deep(button[type="submit"]) {
    background: linear-gradient(135deg, #053272 0%, #00334b 100%);
    color: white;
    font-weight: 600;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
}

.auth-form-content :deep(button[type="submit"]:hover) {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(5, 50, 114, 0.3);
}

.auth-form-footer {
    text-align: center;
    margin-top: 1.5rem;
    color: #6c757d;
}

.auth-link {
    color: #053272;
    font-weight: 600;
    text-decoration: none;
}

.auth-link:hover {
    text-decoration: underline;
}

/* Responsive */
@media (max-width: 900px) {
    .auth-page {
        flex-direction: column;
    }

    .auth-branding {
        display: none;
    }

    .mobile-logo {
        display: inline-block;
    }

    .auth-form-container {
        min-height: 100vh;
    }
}
</style>
