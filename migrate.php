<?php

try {
    
    $db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON;');

    // USERS tablosu
    $db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id TEXT PRIMARY KEY,  -- UUID
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        role TEXT NOT NULL CHECK(role IN ('user', 'company', 'admin')),
        password TEXT NOT NULL,
        company_id TEXT,  -- UUID (nullable)
        balance INTEGER DEFAULT 800,
        status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'pending', 'banned')),
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES bus_companies(id) ON DELETE SET NULL
    );
    ");

    // BUS_COMPANIES tablosu
    $db->exec("
    CREATE TABLE IF NOT EXISTS bus_companies (
        id TEXT PRIMARY KEY,        -- UUID
        name TEXT NOT NULL UNIQUE,
        logo_path TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    ");

    // TRIPS tablosu 
    $db->exec("
    CREATE TABLE IF NOT EXISTS trips (
        id TEXT PRIMARY KEY,         -- UUID
        company_id TEXT NOT NULL,    -- FK
        destination_city TEXT NOT NULL,
        arrival_time TEXT NOT NULL,
        departure_time TEXT NOT NULL,
        departure_city TEXT NOT NULL,
        price INTEGER NOT NULL,
        capacity INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES bus_companies(id) ON DELETE CASCADE
    );
    ");

    // TICKETS tablosu 
    $db->exec("
    CREATE TABLE IF NOT EXISTS tickets (
        id TEXT PRIMARY KEY,         -- UUID
        trip_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'canceled', 'expired')),
        total_price INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
    ");

    // BOOKED_SEATS tablosu 
    $db->exec("
    CREATE TABLE IF NOT EXISTS booked_seats (
        id TEXT PRIMARY KEY,        -- UUID
        ticket_id TEXT NOT NULL,
        seat_number INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        UNIQUE(ticket_id, seat_number)
    );
    ");

    // COUPONS tablosu 
    $db->exec("
    CREATE TABLE IF NOT EXISTS coupons (
        id TEXT PRIMARY KEY,        -- UUID
        code TEXT NOT NULL UNIQUE,
        discount REAL NOT NULL,
        company_id TEXT,
        usage_limit INTEGER NOT NULL,
        expire_date TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES bus_companies(id) ON DELETE CASCADE
    );
    ");

    // USER_COUPONS tablosu 
    $db->exec("
    CREATE TABLE IF NOT EXISTS user_coupons (
        id TEXT PRIMARY KEY,         -- UUID
        coupon_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
    ");

   // echo "<p style='color:green; text-align:center;'> Tüm tablolar oluşturuldu!</p>";

} catch (PDOException $e) {
    echo "<p style='color:red; text-align:center;'> Hata: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
}
?>
