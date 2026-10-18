<?php

namespace App\Security;

use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ImportedTransaction;
use App\Entity\Income;
use App\Entity\IncomeChange;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Seul le propriétaire d'une dépense, d'une catégorie, d'un revenu ou d'une opération importée peut la gérer.
 *
 * @extends Voter<string, Expense|Category|ImportedTransaction|Income|IncomeChange>
 */
class OwnerVoter extends Voter
{
    public const MANAGE = 'MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && ($subject instanceof Expense || $subject instanceof Category || $subject instanceof ImportedTransaction || $subject instanceof Income || $subject instanceof IncomeChange);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->getUser()?->getId() === $user->getId();
    }
}
