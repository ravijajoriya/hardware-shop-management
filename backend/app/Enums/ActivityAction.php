<?php

namespace App\Enums;

/**
 * Everything an admin does that the activity log records.
 */
enum ActivityAction: string
{
    case LoggedIn = 'auth.logged_in';
    case LoggedOut = 'auth.logged_out';
    case LoginFailed = 'auth.login_failed';

    case BillCreated = 'bill.created';
    case BillPaymentReceived = 'payment.on_bill';
    case DuesPaymentReceived = 'payment.towards_dues';

    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';

    case StockEntryCreated = 'stock.entry_created';
    case StockEntryDeleted = 'stock.entry_deleted';
    case StockCorrected = 'stock.corrected';

    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductDeleted = 'product.deleted';
    case ProductImagesAdded = 'product.images_added';
    case ProductImageRemoved = 'product.image_removed';
    case ProductImagesReordered = 'product.images_reordered';

    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryDeleted = 'category.deleted';

    case BrandCreated = 'brand.created';
    case BrandUpdated = 'brand.updated';
    case BrandDeleted = 'brand.deleted';

    public function section(): ActivitySection
    {
        return match ($this) {
            self::LoggedIn, self::LoggedOut, self::LoginFailed => ActivitySection::Logins,
            self::BillCreated => ActivitySection::Bills,
            self::BillPaymentReceived, self::DuesPaymentReceived => ActivitySection::Payments,
            self::CustomerCreated, self::CustomerUpdated => ActivitySection::Customers,
            self::StockEntryCreated, self::StockEntryDeleted, self::StockCorrected => ActivitySection::Stock,
            self::ProductCreated, self::ProductUpdated, self::ProductDeleted,
            self::ProductImagesAdded, self::ProductImageRemoved, self::ProductImagesReordered => ActivitySection::Products,
            self::CategoryCreated, self::CategoryUpdated, self::CategoryDeleted => ActivitySection::Categories,
            self::BrandCreated, self::BrandUpdated, self::BrandDeleted => ActivitySection::Brands,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LoggedIn => 'Logged in',
            self::LoggedOut => 'Logged out',
            self::LoginFailed => 'Failed login',
            self::BillCreated => 'Bill made',
            self::BillPaymentReceived => 'Payment on a bill',
            self::DuesPaymentReceived => 'Payment towards dues',
            self::CustomerCreated => 'Customer added',
            self::CustomerUpdated => 'Customer changed',
            self::StockEntryCreated => 'Stock in',
            self::StockEntryDeleted => 'Stock in deleted',
            self::StockCorrected => 'Stock corrected',
            self::ProductCreated => 'Product added',
            self::ProductUpdated => 'Product changed',
            self::ProductDeleted => 'Product deleted',
            self::ProductImagesAdded => 'Photos added',
            self::ProductImageRemoved => 'Photo removed',
            self::ProductImagesReordered => 'Photo order changed',
            self::CategoryCreated => 'Category added',
            self::CategoryUpdated => 'Category changed',
            self::CategoryDeleted => 'Category deleted',
            self::BrandCreated => 'Brand added',
            self::BrandUpdated => 'Brand changed',
            self::BrandDeleted => 'Brand deleted',
        };
    }

    /**
     * Whether the record it is about is gone afterwards, so there is nothing to link to.
     */
    public function removesSubject(): bool
    {
        return in_array($this, [self::StockEntryDeleted, self::ProductDeleted, self::CategoryDeleted, self::BrandDeleted], true);
    }
}
