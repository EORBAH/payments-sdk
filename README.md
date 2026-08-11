# payments

Bibliothèque PHP (Composer) qui encapsule, derrière une interface commune, plusieurs
fournisseurs de paiement : **Stripe**, **PayPal**, **Visa Direct**, **Orange Money**
(Guinée) et **PayCard** (Guinée).

## Installation

```bash
composer require eor_bah545/payments
```

> Le `name` dans `composer.json` (`payments/payments`) est un exemple — renommez-le
> avec votre propre vendor avant publication sur Packagist si besoin.

## Architecture

```
src/
  Contracts/PaymentGatewayInterface.php   → contrat commun (initiate, getStatus)
  Contracts/RefundableInterface.php       → remboursement (Stripe, PayPal uniquement)
  DTO/PaymentRequest.php                  → requête normalisée
  DTO/PaymentResponse.php                 → réponse normalisée
  DTO/RefundRequest.php
  Enum/PaymentStatusEnum.php              → statut normalisé (initiated/pending/success/failed/cancelled/unknown)
  Enum/PayCardMethodEnum.php              → sous-méthodes PayCard
  Gateways/StripeGateway.php
  Gateways/PayPalGateway.php
  Gateways/VisaGateway.php
  Gateways/OrangeMoneyGateway.php
  Gateways/PayCardGateway.php
  PaymentManager.php                      → registre central des passerelles
```

Chaque passerelle implémente `PaymentGatewayInterface` :

```php
interface PaymentGatewayInterface
{
    public function getName(): string;
    public function initiate(PaymentRequest $request): PaymentResponse;
    public function getStatus(string $reference): PaymentResponse;
}
```

Comme les fournisseurs ont des flux très différents (redirection navigateur,
formulaire carte côté client, push serveur-à-serveur...), `PaymentResponse` expose
plusieurs champs optionnels (`redirectUrl`, `clientSecret`, `status`) que vous
utilisez selon le fournisseur appelé.

## Utilisation rapide — API fluide (recommandée)

Chaque fournisseur a sa propre façade fluide dans le namespace racine `Payments\`.
Pas de `PaymentRequest` à construire à la main, pas de `PaymentManager` : vous
chaînez des méthodes qui parlent le vocabulaire du fournisseur.

```php
use Payments\OrangeMoney;

$response = OrangeMoney::create($clientId, $clientSecret, $merchantKey, 'gn')
    ->amount(50000)  // GNF, pas de sous-unité
    ->order('CMD-2026-001')
    ->returnTo('https://monsite.com/paiement/retour')
    ->cancelTo('https://monsite.com/paiement/annule')
    ->notify('https://monsite.com/paiement/webhook')
    ->pay();

header('Location: ' . $response->redirectUrl);
```

```php
use Payments\Stripe;

$response = Stripe::create('sk_test_...')
    ->amount(5000, 'usd')   // centimes
    ->order('CMD-2026-002')
    ->email('client@example.com')
    ->pay();

echo $response->clientSecret; // à transmettre au front (Stripe.js)
```

```php
use Payments\PayPal;

$response = PayPal::create($clientId, $secret, sandbox: true)
    ->amount(50.00, 'USD')
    ->order('CMD-2026-003')
    ->returnTo('https://monsite.com/retour')
    ->cancelTo('https://monsite.com/annule')
    ->pay();

header('Location: ' . $response->redirectUrl);

// Au retour du client :
$result = PayPal::create($clientId, $secret, true)->capture($orderId);
```

```php
use Payments\PayCard;
use Payments\Enum\PayCardMethodEnum;

$response = PayCard::create($merchantCode, $apiKey)
    ->amount(50000)
    ->order('CMD-2026-004')
    ->method(PayCardMethodEnum::ORANGE_MONEY)
    ->notify('https://monsite.com/webhook')
    ->phone('+224620000000')
    ->pay();

header('Location: ' . $response->redirectUrl);
```

```php
use Payments\Visa;

$response = Visa::create($apiKey, $sharedSecret, sandbox: true)
    ->amount(50.00, 'USD')
    ->order('CMD-2026-005')
    ->card('4957030420210462')
    ->acquiringBin('408999')
    ->push();
```

⚠️ **Note sur `amount`** : `Stripe`, `Visa` et `PayPal` (unité **principale**,
converti en centimes en interne pour PayPal/Visa — voir signature `amount(float)`)
gèrent des devises à sous-unités. `OrangeMoney` et `PayCard`, utilisés en franc
guinéen (GNF, sans sous-unité), attendent un montant entier via `amount(int)`.
Adaptez selon la devise réellement utilisée.

Chaque façade renvoie un `Payments\DTO\PaymentResponse` — toujours les mêmes
champs (`success`, `reference`, `status`, `redirectUrl`, `clientSecret`, `raw`),
donc pas de surprise en changeant de fournisseur.

## Utilisation avancée — plusieurs fournisseurs au même endroit

Si votre app doit choisir dynamiquement le fournisseur (ex: sélecteur au checkout),
`PaymentManager` reste disponible et s'appuie sur les mêmes classes `Gateways\*` :

```php
use Payments\PaymentManager;
use Payments\Gateways\{StripeGateway, OrangeMoneyGateway};
use Payments\DTO\PaymentRequest;

$manager = new PaymentManager();
$manager->register(new StripeGateway(getenv('STRIPE_SECRET_KEY')));
$manager->register(new OrangeMoneyGateway(
    getenv('OM_CLIENT_ID'), getenv('OM_CLIENT_SECRET'), getenv('OM_MERCHANT_KEY'), 'gn'
));

$choix = $_POST['moyen']; // "stripe" ou "orange_money"
$response = $manager->gateway($choix)->initiate(new PaymentRequest(
    amount: 50000,
    currency: 'GNF',
    orderId: 'CMD-2026-006',
));
```

## Détail par fournisseur

### Stripe — `StripeGateway`

Basé sur le SDK officiel `stripe/stripe-php` et l'API **PaymentIntents**.
`initiate()` crée un PaymentIntent et renvoie son `clientSecret` : c'est votre
front-end (Stripe.js / Payment Element) qui confirme le paiement, votre serveur
ne manipule jamais le numéro de carte.

Docs officielles : https://docs.stripe.com/api/payment_intents · https://docs.stripe.com/payments/accept-a-payment

### PayPal — `PayPalGateway`

Basé sur l'API REST officielle **Orders v2**. `initiate()` crée une commande et
renvoie le lien `approve` vers lequel rediriger le client. Après retour sur votre
`returnUrl`, appelez `PayPalGateway::capture($orderId)` pour finaliser le débit.

Docs officielles : https://developer.paypal.com/docs/api/orders/v2/ · https://developer.paypal.com/api/rest/authentication/

### Visa Direct — `VisaGateway`

Basé sur l'API officielle **Visa Direct / Funds Transfer (pushfundstransactions)**,
qui permet de créditer directement une carte Visa (paiement "push"), différent
d'un encaissement e-commerce classique. Authentification par **X-Pay-Token**
gérée automatiquement ; en production, Visa impose en plus une authentification
**mutuelle TLS** (certificat client délivré par Visa) — passez un client Guzzle
préconfiguré avec les options `cert`/`ssl_key` en 4ᵉ argument du constructeur.

Champs spécifiques à fournir via `PaymentRequest::$metadata` : voir le docblock
de `VisaGateway`.

Docs officielles : https://developer.visa.com/capabilities/visa_direct/docs · https://developer.visa.com/capabilities/visa_direct/docs-authentication

### Orange Money (Guinée) — `OrangeMoneyGateway`

Basé sur l'API officielle **Orange Money Web Payment / M Payment** du Orange
Developer Portal, disponible en Guinée (`env = "gn"`). Flux : OAuth2 →
création du paiement (`payment_url` à ouvrir/rediriger, le client valide via
un OTP USSD reçu sur son téléphone) → vérification du statut.

```php
$response = $manager->gateway('orange_money')->initiate($request);
// $response->redirectUrl → à ouvrir dans le navigateur du client

$status = $manager->gateway('orange_money')->getStatus(
    $response->reference, // pay_token
    $request->orderId,
    $request->amount,
);
```

Docs officielles : https://developer.orange.com/apis/om-webpay (souscription obligatoire pour obtenir `client_id`/`client_secret`/`merchant_key`).

### PayCard (Guinée) — `PayCardGateway`

PayCard est un agrégateur guinéen qui route lui-même vers Orange Money, MTN MoMo,
carte bancaire ou son porte-monnaie natif.

**⚠️ Point d'attention** : au moment de la rédaction de ce package, PayCard ne
publie pas de référence REST publique en ligne. `PayCardGateway` reconstitue le
flux `createPayment()` / `getPaymentStatus()` à partir du comportement documenté
de leur intégration (cohérent avec la bibliothèque communautaire `paycard4j`).
**Avant toute mise en production, confirmez auprès de PayCard (paycard.co) l'URL
de base réelle de l'API et le format exact des champs**, puis ajustez
`PayCardGateway::$baseUrl` et le payload si nécessaire.

```php
use Payments\Enum\PayCardMethodEnum;

$request = new PaymentRequest(
    amount: 50000,
    currency: 'GNF',
    orderId: 'CMD-002',
    description: 'Commande #002',
    notifyUrl: 'https://monsite.com/paiement/webhook',
    paymentMethod: PayCardMethodEnum::ORANGE_MONEY->value,
);
$response = $manager->gateway('paycard')->initiate($request);
```

## Tests

```bash
composer install
composer test
```

Les tests fournis (`tests/`) couvrent `PaymentManager` et `OrangeMoneyGateway`
(avec un client HTTP mocké — aucun appel réseau réel, aucune clé requise).

## Aller plus loin

- Stockez vos identifiants (`STRIPE_SECRET_KEY`, `PAYPAL_CLIENT_ID`, `OM_MERCHANT_KEY`...)
  dans des variables d'environnement, jamais en dur dans le code.
- Chaque webhook/`notif_url`/`callbackUrl` doit être vérifié côté serveur avant
  de considérer une commande comme payée : ne faites jamais confiance uniquement
  au `returnUrl` navigateur, toujours à la notification serveur-à-serveur ou à
  un appel explicite à `getStatus()`.
- Ce package ne stocke ni ne journalise vos transactions : ajoutez votre propre
  persistance (base de données) autour de `PaymentManager`.
