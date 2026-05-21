<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;

final class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Nom affiché',
                'required' => false,
                'constraints' => [
                    new Length(
                        max: 120,
                        maxMessage: 'Le nom affiché ne doit pas dépasser {{ limit }} caractères.',
                    ),
                ],
            ])
            ->add('avatarFile', FileType::class, [
                'label' => 'Changer l’avatar',
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
                ],
            ])
            ->add('receivePublicationEmails', CheckboxType::class, [
                'label' => 'Recevoir un email lors des nouvelles publications',
                'required' => false,
                'help' => 'Recevoir un email lorsqu’un nouvel article, une randonnée ou une visite est publié.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
