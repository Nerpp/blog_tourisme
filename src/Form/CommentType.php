<?php

namespace App\Form;

use App\Entity\Comment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Blank;

/** @extends AbstractType<Comment> */
class CommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', TextareaType::class, [
                'label' => 'Commentaire',
                'attr' => [
                    'rows' => 5,
                    'maxlength' => 5000,
                    'placeholder' => 'Ajoutez un commentaire…',
                ],
                'label_attr' => [
                    'class' => 'sr-only',
                ],
            ])
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => false,
                'constraints' => [
                    new Blank(message: 'Votre commentaire n’a pas pu être envoyé.'),
                ],
                'row_attr' => [
                    'class' => 'comment-honeypot',
                    'aria-hidden' => 'true',
                ],
                'attr' => [
                    'autocomplete' => 'off',
                    'tabindex' => '-1',
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => $options['submit_label'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Comment::class,
            'submit_label' => 'Envoyer',
        ]);

        $resolver->setAllowedTypes('submit_label', 'string');
    }
}
