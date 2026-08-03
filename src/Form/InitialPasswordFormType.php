<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Constraints\Regex;

/** @extends AbstractType<array<string, mixed>> */
final class InitialPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'invalid_message' => 'validation.password.mismatch',
            'first_options' => [
                'label' => 'Mot de passe',
                'help' => 'Utilisez au moins 12 caractères. Une phrase courte avec plusieurs mots est souvent plus sûre qu’un mot simple.',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => [
                'label' => 'Confirmer le mot de passe',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'constraints' => [
                new NotBlank(message: 'validation.password.required'),
                new Length(
                    min: 12,
                    max: 4096,
                    minMessage: 'validation.password.too_short',
                ),
                new Regex(
                    pattern: '/^(?=.*[A-Za-zÀ-ÿ])(?=.*\d).+$/u',
                    message: 'validation.password.letter_and_number_required',
                ),
                new PasswordStrength(
                    minScore: PasswordStrength::STRENGTH_STRONG,
                    message: 'validation.password.too_weak',
                ),
                new NotCompromisedPassword(message: 'validation.password.compromised'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'initial-password',
        ]);
    }
}
