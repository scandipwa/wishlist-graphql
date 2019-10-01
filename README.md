# ScandiPWA_WishlistGraphQl

**WishlistGraphQl** provides additional resolvers for wishlist, extending Magento_WishlistGraphQl. 


### AddProductToWishlist

This endpoint allows to add product to Wishlist

```graphql
mutation AddProductToWishlist($wishlistItem: WishlistItemInput!) {
    addProductToWishlist(wishlistItem: $wishlistItem) {
         id
         sku
         qty
         description
         added_at
         product
    }
}
```

```json
{
   "wishlistItem": {
       "sku": "n31189077-1",
       "quantity": 2,
       "description": "Description",
       "product_option": {
           "extension_attributes": {}
       }
   }
}
```


### RemoveProductFromWishlist

This endpoint allows removing item from wishlist

```graphql
mutation RemoveProductFromWishlist($item_id: Int!) {
    removeProductFromWishlist(item_id: $item_id)
}
```

```json
{
   "item_id": 1
}
```

### UpdateWishlistItem

This endpoint allows to update wishlist item

```graphql
mutation UpdateWishlistItem($itemId: String!, $quantity: Int, $description: String) {
    updateWishlistItem(itemId: $itemId, quantity: $quantity, description: $description) {
        id
        sku
        qty
        description
        added_at
        product
    }
}
```

```json
{
    "itemId": 1,
    "quantity": 2,
    "description": "Description"
}
```

### MoveWishlistToCart

This endpoint allows to move all wishlist items to cart

```graphql
mutation MoveWishlistToCart {
    moveWishlistToCart()
}
```

### ClearWishlist

This endpoint allows to clear wishlist

```graphql
mutation ClearWishlist {
    clearWishlist()
}
```
