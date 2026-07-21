<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;

/** @extends AbstractType<array<string, mixed>> */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'invalid_message' => 'validation.password.mismatch',
            'first_options' => [
                'label' => 'Nouveau mot de passe',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => [
                'label' => 'Confirmer le nouveau mot de passe',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'constraints' => [
                new NotBlank(message: 'validation.password.required'),
                new Length(
                    min: 12,
                    max: 4096,
                    minMessage: 'validation.password.too_short',
                ),
                new PasswordStrength(
                    minScore: PasswordStrength::STRENGTH_MEDIUM,
                    message: 'validation.password.too_predictable',
                ),
                new NotCompromisedPassword(message: 'validation.password.compromised'),
            ],
        ]);
    }
}
