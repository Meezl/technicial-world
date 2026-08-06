<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);

const page = usePage();
const denialReason = computed(() => page.props.flash?.verification_denied_reason);
// Set when an expired / already-used verification link bounced the user
// back here instead of showing the bare 403 "Invalid signature." page.
const flashError = computed(() => page.props.flash?.error);
</script>

<template>
    <GuestLayout>
        <Head title="Email Verification" />

        <div v-if="denialReason" class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800">
            <strong>Access paused.</strong><br />
            {{ denialReason }}
        </div>

        <div v-if="flashError" class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            {{ flashError }}
        </div>

        <div class="mb-4 text-sm text-gray-600">
            Thanks for signing up! Before getting started, could you verify your
            email address by clicking on the link we just emailed to you? If you
            didn't receive the email, we will gladly send you another.
        </div>

        <div
            class="mb-4 text-sm font-medium text-green-600"
            v-if="verificationLinkSent"
        >
            A new verification link has been sent to the email address you
            provided during registration.
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Resend Verification Email
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >Log Out</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
