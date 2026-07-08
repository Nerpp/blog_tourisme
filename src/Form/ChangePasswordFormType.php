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
            'invalid_message' => 'Les mots de passe ne correspondent pas.',
            'first_options' => [
                'label' => 'Nouveau mot de passe',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => [
                'label' => 'Confirmer le nouveau mot de passe',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'constraints' => [
                new NotBlank(message: 'Veuillez saisir un mot de passe.'),
                new Length(
                    min: 12,
                    max: 4096,
                    minMessage: 'Votre mot de passe doit contenir au moins {{ limit }} caractères.',
                ),
                new PasswordStrength(
                    minScore: PasswordStrength::STRENGTH_MEDIUM,
                    message: 'Votre mot de passe est trop prévisible. Utilisez une phrase plus longue, avec plusieurs mots, chiffres ou symboles.',
                ),
                new NotCompromisedPassword(message: 'Ce mot de passe est connu dans des fuites de données. Choisissez-en un autre.'),
            ],
        ]);
    }
}
