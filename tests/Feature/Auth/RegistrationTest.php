<?php

use App\Mail\WelcomeEmail;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Features;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register with an eight-character lowercase password', function () {
    Mail::fake();
    $this->app->detectEnvironment(fn (): string => 'production');
    Http::preventStrayRequests();

    $password = 'abcdefgh';

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    $response->assertRedirect(route('workspaces.index', absolute: false));
    Mail::assertSent(WelcomeEmail::class, fn (WelcomeEmail $mail): bool => $mail->hasTo('test@example.com'));
});

test('registration succeeds when welcome email delivery fails', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    Exceptions::fake();

    $pendingMail = Mockery::mock(PendingMail::class);
    $pendingMail->shouldReceive('send')
        ->once()
        ->andThrow(new TransportException('SMTP rejected the recipient.'));

    Mail::shouldReceive('to')
        ->once()
        ->andReturn($pendingMail);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'abcdefgh',
        'password_confirmation' => 'abcdefgh',
    ]);

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    $response->assertRedirect(route('workspaces.index', absolute: false));
    Exceptions::assertReported(TransportException::class);
});

test('invalid registrations do not send a welcome email', function () {
    Mail::fake();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'invalid-email',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertDatabaseCount('users', 0);
    Mail::assertNothingSent();
});
