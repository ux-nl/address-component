# Address Component

Filament address form component with postcode lookup for the Netherlands, Belgium, Germany, France and Luxembourg.

Fills street, city and GPS coordinates automatically from a postcode and house number, and clears the coordinates again when the address changes — so a form never keeps the location of a previous address.

Maintained by [UX Nederland](https://ux.nl).

## Installation

This package is not published on Packagist. Add the repository to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:ux-nl/address-component.git"
        }
    ]
}
```

Then require it:

```bash
composer require ux-nl/address-component
```

Both API keys used by the lookup must be configured in `config/services.php`:

```php
'google' => [
    'api_key' => env('GOOGLE_MAPS_API_KEY'),
],

'postcode_tech' => [
    'api_key' => env('POSTCODE_TECH_API_KEY'),
],
```

## Usage

### Address form fields

`AddressGroup::make()` returns a `Grid` with postcode, house number, addition, street, city and country fields. Postcode and house number are live, and a complete pair triggers the lookup.

```php
use Chargit\AddressComponent\AddressGroup;

public function form(Schema $schema): Schema
{
    return $schema->components([
        AddressGroup::make('location'),
    ]);
}
```

The state path prefix is the first argument, so the example above writes to `location.postalCode`, `location.street`, `location.latitude` and so on.

| Argument | Default | Purpose |
| --- | --- | --- |
| `$prefix` | `'address'` | State path prefix for the nested fields |
| `$required` | `true` | Marks the fields required. Accepts a `Closure` for conditional forms |
| `$showCoordinates` | `false` | Shows a visible "GPS locatie" field instead of hidden lat/lng inputs |
| `$coordinatesRequired` | `false` | Requires coordinates. Only effective with `$showCoordinates` |

A Dutch postcode must be complete (four digits plus two letters) before the lookup runs — an incomplete one would fuzzy-match onto the wrong street.

### Table column

`LocationColumn` renders an address stored across several columns as one cell:

```php
use Chargit\AddressComponent\LocationColumn;

LocationColumn::make('address')
    ->streetField('street')
    ->houseNumberField('house_number')
    ->houseNumberAdditionField('house_number_addition')
    ->cityField('city');
```

### Direct lookups

```php
use Chargit\AddressComponent\PostcodeLookupService;

$address = app(PostcodeLookupService::class)->lookup('4921JN', '20', 'NLD');
// ['street' => 'Zilverschoon', 'city' => 'Made', 'postalCode' => '4921JN',
//  'houseNumber' => '20', 'latitude' => '51.6775', 'longitude' => '4.7817']
```

Returns `null` when the address cannot be resolved, and notifies the user.

## Note on namespaces

The composer package is `ux-nl/address-component`, but the PHP namespace is still `Chargit\AddressComponent`. That is deliberate: renaming it would be a breaking change for every dependent.

## Testing

```bash
composer test
```

## License

Proprietary. See [LICENSE.md](LICENSE.md).
