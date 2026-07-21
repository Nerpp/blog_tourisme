<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Constraints\Regex;

/** @extends AbstractType<User> */
class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
            ])
            ->add('displayName', TextType::class, [
                'label' => 'Nom affiché',
                'required' => true,
                'help' => 'Ce nom sera visible publiquement sur vos commentaires.',
            ])
            ->add('avatarFile', FileType::class, [
                'label' => 'Image de profil',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File(
                        maxSize: '5M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        maxSizeMessage: 'validation.avatar.max_size',
                        mimeTypesMessage: 'validation.avatar.mime_type',
                    ),
                ],
                'attr' => [
                    'accept' => 'image/jpeg,image/png,image/webp',
                    'data-avatar-input' => 'true',
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'validation.password.mismatch',
                'first_options' => [
                    'label' => 'Mot de passe',
                    'help' => 'Utilisez au moins 12 caractères. Une phrase courte avec plusieurs mots est souvent plus sûre qu’un mot simple.',
                ],
                'second_options' => [
                    'label' => 'Confirmer le mot de passe',
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
                    new NotCompromisedPassword(
                        message: 'validation.password.compromised',
                    ),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Créer mon compte',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
