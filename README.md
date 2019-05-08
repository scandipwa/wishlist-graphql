# ScandiPWA_WishlistGraphQl

**WishlistGraphQl** provides additional resolvers for wishlist, extending Magento_WishlistGraphQl. 


### AddProductToWishlist

This endpoint allows to add product to Wishlist

```graphql
mutation AddProductToWishlist($productSku: String!) {
    addProductToWishlist(productSku: $productSku) {
         id
         qty
         description
         added_at
         product
    }
}
```

```json
{
   "product_sku": "n31189077-1"
}
```


### RemoveProductFromWishlist

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
