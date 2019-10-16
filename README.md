# ScandiPWA_WishlistGraphQl

**WishlistGraphQl** provides additional resolvers for wishlist, extending Magento_WishlistGraphQl. 


### SaveWishlistItem

This endpoint allows to save Wishlist item

```graphql
mutation SaveWishlistItem($wishlistItem: WishlistItemInput!) {
    saveWishlistItem(wishlistItem: $wishlistItem) {
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
mutation RemoveProductFromWishlist($item_id: ID!) {
    removeProductFromWishlist(item_id: $item_id)
}
```

```json
{
   "item_id": 1
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
