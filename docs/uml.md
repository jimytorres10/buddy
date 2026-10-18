# Buddy — diagrammes UML

Montants en centimes (`int`). Toutes les données appartiennent à un `User` (suppression en cascade).

## 1. Modèle de données (entités Doctrine)

```mermaid
classDiagram
    direction LR

    class User {
        +int id
        +string email
        +array roles
        +string password
        +string firstName
        +bool onboardingCompleted
        +DateTimeImmutable createdAt
    }

    class Category {
        +int id
        +string name
        +string icon
        +string color
        +int position
    }

    class Income {
        +int id
        +string label
        +int amount
    }

    class FixedExpense {
        +int id
        +string label
        +int amount
        +DateTimeImmutable? startMonth
        +DateTimeImmutable? endMonth
        +hasPeriod() bool
        +isActiveIn(month) bool
        +getStatusIn(month) string
    }

    class Budget {
        +int id
        +int amount
    }

    class MonthlyBudget {
        +int id
        +DateTimeImmutable month
        +int amount
    }

    class Expense {
        +int id
        +int amount
        +DateTimeImmutable date
        +string? label
        +DateTimeImmutable createdAt
        +getDisplayLabel() string
    }

    class ImportedTransaction {
        +int id
        +string hash
        +DateTimeImmutable date
        +string rawLabel
        +string label
        +int amount
        +string status
        +DateTimeImmutable importedAt
        +DateTimeImmutable? classifiedAt
        +isCredit() bool
        +classify(status, ...) 
        +reset()
    }

    class ImportStatus {
        <<enumeration>>
        pending
        expense
        fixed
        income
        ignored
        matched
    }

    User "1" --> "*" Category
    User "1" --> "*" Income
    User "1" --> "*" FixedExpense
    User "1" --> "*" Budget
    User "1" --> "*" MonthlyBudget
    User "1" --> "*" Expense
    User "1" --> "*" ImportedTransaction

    Budget "*" --> "1" Category : budget habituel (1 par catégorie)
    MonthlyBudget "*" --> "1" Category : ajustement d'un mois
    Expense "*" --> "1" Category
    FixedExpense "*" --> "0..1" Category

    ImportedTransaction "*" --> "0..1" Category
    ImportedTransaction "*" --> "0..1" Expense : crée (expense)
    ImportedTransaction "*" --> "0..1" FixedExpense : crée (fixed) / pointe (matched)
    ImportedTransaction "*" --> "0..1" Income : crée (income)
    ImportedTransaction ..> ImportStatus : status
```

Règles notables :
- **Budget effectif d'un mois** = `MonthlyBudget` du mois s'il existe (0 = pas de budget ce mois-ci), sinon `Budget` habituel.
- **Dépense fixe active** un mois donné si `startMonth ≤ mois ≤ endMonth` (bornes facultatives).
- **Déduplication des imports** : `hash` unique par utilisateur (FITID OFX, sinon date + montant + libellé + rang).

## 2. Architecture applicative

```mermaid
classDiagram
    direction TB

    namespace Controllers {
        class SecurityController
        class OnboardingController
        class DashboardController
        class ExpenseController
        class PlanningController
        class StatsController
        class ImportController
        class MenuController
    }

    namespace Services {
        class MonthSummary {
            +compute(user, month) array
        }
        class StatsReport {
            +build(user, month) array
            +impact(user, month) array
        }
        class PlanningForms {
            +handle(section, user, request) array
        }
        class CategoryDefaults
    }

    namespace Import {
        class StatementParser {
            +parse(content, filename) array
        }
        class LabelCleaner {
            +clean(raw) string
        }
        class CategorySuggester {
            +suggest(tx, categories) Category
        }
        class FixedExpenseMatcher {
            +match(tx) array
        }
        class TransactionImporter {
            +import(user, content, filename) array
            +toExpense() / toFixedExpense() / toIncome()
            +matchFixedExpense() / ignore() / undo()
        }
    }

    namespace Transverse {
        class OwnerVoter
        class OnboardingSubscriber
        class AppExtension
        class RecleanImportLabelsCommand
    }

    DashboardController --> MonthSummary
    PlanningController --> MonthSummary
    PlanningController --> PlanningForms
    PlanningController --> StatsReport
    OnboardingController --> PlanningForms
    StatsController --> StatsReport
    StatsReport --> MonthSummary
    SecurityController --> CategoryDefaults

    ImportController --> TransactionImporter
    ImportController --> CategorySuggester
    ImportController --> FixedExpenseMatcher
    TransactionImporter --> StatementParser
    TransactionImporter --> LabelCleaner
    RecleanImportLabelsCommand --> LabelCleaner

    ExpenseController ..> OwnerVoter : IsGranted
    ImportController ..> OwnerVoter : IsGranted
```

## 3. Cycle de vie d'une opération importée

```mermaid
stateDiagram-v2
    [*] --> pending : import (OFX / CSV)
    pending --> expense : catégorie choisie → crée une Expense
    pending --> fixed : nouvelle dépense fixe → crée une FixedExpense
    pending --> matched : pointée sur une FixedExpense existante
    pending --> income : crédit → crée un Income
    pending --> ignored : ignorer
    expense --> pending : annuler (supprime l'Expense)
    fixed --> pending : annuler (supprime la FixedExpense)
    income --> pending : annuler (supprime l'Income)
    matched --> pending : annuler (garde la FixedExpense)
    ignored --> pending : annuler
```
