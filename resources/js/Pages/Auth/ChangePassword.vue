<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

// No current-password field on purpose: the user read the one they are
// replacing off an email a moment ago, so asking them to retype it proves
// nothing and only adds a step to a screen they cannot leave.
const form = useForm({
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.change.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout
        title="One more step"
        subtitle="Set a password only you know"
        :show-register="false"
    >
        <Head title="Set your password" />

        <div class="mb-4 text-sm text-gray-600">
            <p class="mt-2">
                You signed in with the temporary password we emailed you. Because it
                travelled by email, it only works once — choose your own now and it
                stops working.
            </p>
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="password" value="New password" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autofocus
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel for="password_confirmation" value="Confirm new password" />

                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Save and continue
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
