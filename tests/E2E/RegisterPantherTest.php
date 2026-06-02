<?php

namespace App\Tests\E2E;

use Facebook\WebDriver\WebDriverBy;

final class RegisterPantherTest extends PantherTestCase
{
    public function testRegisterFormCanBeCompletedAndSubmitted(): void
    {
        $client = self::createBrowser();
        $client->request('GET', '/register');

        self::assertSelectorIsVisible('form.register-form');
        self::assertSelectorExists('input[name="registration_form[email]"]');

        $email = $this->uniqueEmail('register');
        $password = 'E2E Register Password 2026 9!';

        $webDriver = $client->getWebDriver();
        $webDriver->findElement(WebDriverBy::name('registration_form[email]'))->sendKeys($email);
        $webDriver->findElement(WebDriverBy::name('registration_form[displayName]'))->sendKeys('E2E Register '.bin2hex(random_bytes(4)));
        $webDriver->findElement(WebDriverBy::name('registration_form[plainPassword][first]'))->sendKeys($password);
        $webDriver->findElement(WebDriverBy::name('registration_form[plainPassword][second]'))->sendKeys($password);
        $webDriver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

        $client->waitFor('.login-page, .register-page');

        self::assertStringContainsString('/login', $client->getCurrentURL());
        self::assertSelectorTextContains('body', 'Votre compte a été créé');
    }

    public function testAvatarPreviewAppearsWhenImageIsSelected(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            self::markTestSkipped('GD PNG support is required to create the avatar fixture image.');
        }

        $imagePath = sys_get_temp_dir().'/panther-avatar-'.bin2hex(random_bytes(6)).'.png';
        $image = imagecreatetruecolor(96, 96);
        self::assertNotFalse($image);
        imagepng($image, $imagePath);
        imagedestroy($image);

        try {
            $client = self::createBrowser();
            $client->request('GET', '/register');

            self::assertSelectorIsVisible('form.register-form');
            $client->getWebDriver()
                ->findElement(WebDriverBy::cssSelector('input[name="registration_form[avatarFile]"]'))
                ->sendKeys($imagePath);

            $client->waitFor('[data-avatar-preview].has-image img:not([hidden])');
            self::assertSelectorExists('[data-avatar-preview].has-image img:not([hidden])');
        } finally {
            if (is_file($imagePath)) {
                unlink($imagePath);
            }
        }
    }
}
