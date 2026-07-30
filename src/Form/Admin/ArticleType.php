<?php

namespace App\Form\Admin;

use App\Entity\Article;
use App\Entity\Category;
use App\Service\Article\ArticleContentSanitizer;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;

/** @extends AbstractType<Article> */
final class ArticleType extends AbstractType
{
    public function __construct(
        private readonly ArticleContentSanitizer $articleContentSanitizer,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('excerpt', TextareaType::class, [
                'required' => false,
            ])
            ->add('content', TextareaType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choices' => $options['article_categories'],
                'required' => false,
                'placeholder' => 'Non renseignée',
            ])
            ->add('galleryImages', FileType::class, [
                'mapped' => false,
                'multiple' => true,
                'required' => false,
                'constraints' => [
                    new All(
                        constraints: [
                            new File(
                                maxSize: '30M',
                                mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                                maxSizeMessage: 'Chaque image doit peser au maximum 30 Mo.',
                                mimeTypesMessage: 'Chaque image doit être au format JPG, PNG ou WebP.',
                            ),
                        ],
                    ),
                ],
            ])
            ->add('saveDraft', SubmitType::class)
            ->add('publish', SubmitType::class);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $article = $event->getData();
            if (!$article instanceof Article) {
                return;
            }

            $article
                ->setTitle(trim((string) $article->getTitle()))
                ->setExcerpt($this->nullIfBlank($article->getExcerpt()))
                ->setContent($this->articleContentSanitizer->sanitize(trim((string) $article->getContent())));
        }, 2048);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'allow_extra_fields' => true,
            'csrf_protection' => false,
            'data_class' => Article::class,
            'article_categories' => [],
            'validation_groups' => static function (FormInterface $form): array {
                $publishButton = $form->get('publish');
                if ($publishButton instanceof ClickableInterface && $publishButton->isClicked()) {
                    return ['Default', 'publish'];
                }

                return ['Default', 'draft'];
            },
        ]);

        $resolver->setAllowedTypes('article_categories', 'array');
        $resolver->setAllowedValues('article_categories', static function (array $categories): bool {
            foreach ($categories as $category) {
                if (!$category instanceof Category) {
                    return false;
                }
            }

            return true;
        });
    }

    public function getBlockPrefix(): string
    {
        return '';
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
