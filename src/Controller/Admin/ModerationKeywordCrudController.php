<?php

namespace App\Controller\Admin;

use App\Entity\ModerationKeyword;
use App\Enum\ModerationKeywordType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class ModerationKeywordCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ModerationKeyword::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Mot-clé de modération')
            ->setEntityLabelInPlural('Mots-clés de modération')
            ->setDefaultSort(['keyword' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('keyword', 'Mot-clé');
        yield ChoiceField::new('type', 'Type')
            ->setChoices($this->enumChoices(ModerationKeywordType::cases()));
        yield BooleanField::new('enabled', 'Actif');
        yield DateTimeField::new('createdAt', 'Création')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Mise à jour')->hideOnForm();
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, \BackedEnum>
     */
    private function enumChoices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->value] = $case;
        }

        return $choices;
    }
}
