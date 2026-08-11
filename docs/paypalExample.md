Voici le flux complet PayPal avec la nouvelle façade fluide `Payments\PayPal` :

**1. Créer le paiement et rediriger le client**

```php
require 'vendor/autoload.php';

use Payments\PayPal;

$response = PayPal::create(
    clientId: 'VOTRE_CLIENT_ID',   // developer.paypal.com → My Apps & Credentials
    clientSecret: 'VOTRE_SECRET',
    sandbox: true,                 // false en production
)
    ->amount(50.00, 'USD')         // unité principale, pas de centimes à calculer
    ->order('CMD-2026-001')
    ->description('Commande #001')
    ->returnTo('https://monsite.com/paypal/retour')
    ->cancelTo('https://monsite.com/paypal/annule')
    ->pay();

// Sauvegardez $response->reference (l'orderId PayPal) en base, lié à CMD-2026-001
$_SESSION['paypal_order_id'] = $response->reference;

header('Location: ' . $response->redirectUrl); // lien "approve" PayPal
exit;
```

Notez la différence avec avant : plus besoin de convertir 50.00 → 5000 centimes, `amount()` prend directement le montant en unité principale.

**2. Capturer le paiement au retour** (`https://monsite.com/paypal/retour`)

PayPal ne débite rien tant que vous n'appelez pas `capture()` :

```php
use Payments\PayPal;

$paypal = PayPal::create('VOTRE_CLIENT_ID', 'VOTRE_SECRET', sandbox: true);

$orderId = $_GET['token'] ?? $_SESSION['paypal_order_id'];
$result = $paypal->capture($orderId);

if ($result->success) {
    // marquer CMD-2026-001 comme payée
    echo "Paiement réussi : " . $result->reference;
} else {
    echo "Échec : " . $result->status->value;
}
```

**3. Vérifier le statut à tout moment**

```php
$status = $paypal->status($orderId);
echo $status->status->value; // "success", "pending", "failed"...
```

**4. Rembourser**

```php
// $captureId se trouve dans $result->raw['purchase_units'][0]['payments']['captures'][0]['id']
$refund = $paypal->refund(
    captureId: $captureId,
    amount: 50.00,        // null = remboursement total, en unité principale
    reason: 'Produit défectueux',
);
```

Le principe reste le même qu'avant (créer → rediriger → capturer au retour), seule la façon d'écrire le code change : chaînage fluide, montants en unité principale, plus de `PaymentRequest`/`PaymentManager` à manipuler pour un usage simple mono-fournisseur.