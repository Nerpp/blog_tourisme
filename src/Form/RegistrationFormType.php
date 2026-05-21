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
use Symfony\Component\Validator\Constraints\Regex;

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
                'required' => false,
            ])
            ->add('avatarFile', FileType::class, [
                'label' => 'Image de profil',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File(
                        maxSize: '2M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        maxSizeMessage: 'L’image de profil ne doit pas dépasser 2 Mo.',
                        mimeTypesMessage: 'Formats acceptés : JPG, PNG ou WebP.',
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
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Mot de passe',
                    'help' => 'Utilisez au moins 12 caractères, avec des lettres et des chiffres. Évitez les mots de passe simples comme 12345678.',
                ],
                'second_options' => [
                    'label' => 'Confirmer le mot de passe',
                ],
                'constraints' => [
                    new NotBlank(message: 'Veuillez saisir un mot de passe.'),
                    new Length(
                        min: 12,
                        max: 4096,
                        minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
                    ),
                    new Regex(
                        pattern: '/^(?=.*[A-Za-zÀ-ÿ])(?=.*\d).+$/u',
                        message: 'Le mot de passe doit contenir au moins une lettre et un chiffre.',
                    ),
                    new Regex(
                        pattern: '/^(?!.*(?:123456|12345678|123456789|password|azerty|qwerty|motdepasse)).*$/i',
                        message: 'Ce mot de passe est trop courant. Choisissez un mot de passe plus difficile à deviner.',
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
