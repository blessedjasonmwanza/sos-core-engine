# SOS Core Engine

The backend API engine for the SOS applications, built with [Laravel](https://laravel.com).

## 📋 Prerequisites

Ensure you have the following installed on your local machine:

*   **PHP 8.2+**
*   **Composer**
*   **Node.js & NPM** (optional, for frontend assets if needed)
*   **SQLite** (easiest for local dev) or **MySQL/MariaDB**

## 🚀 Installation & Setup

Follow these steps to get the backend running locally:

### 1. Clone the Repository

```bash
git clone https://github.com/blessedjasonmwanza/sos-core-engine.git
cd sos-core-engine
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Environment Setup

Copy the example environment file and configure it:

```bash
cp .env.example .env
```

Open `.env` and check the configuration. By default, it is set up to use `sqlite` for simplicity.

### 4. Generate Application Key

```bash
php artisan key:generate
```

### 5. Database Setup

If using SQLite (default):
```bash
touch database/database.sqlite
```

Run migrations to set up the database schema:
```bash
php artisan migrate
```

(Optional) Seed the database with initial data:
```bash
php artisan db:seed
```

### 6. Serve the Application

Start the local development server:

```bash
php artisan serve
```

The API will be accessible at: `http://localhost:8000`

## 🛠️ Configuration

### Database
To switch to MySQL, update `.env`:

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sos_db
DB_USERNAME=root
DB_PASSWORD=your_password
```

### SSL Configuration
If connecting to a managed database requiring SSL (e.g., Azure MySQL), you can set the CA certificate path:

```ini
MYSQL_ATTR_SSL_CA=/path/to/DigiCertGlobalRootCA.crt.pem
```

## 🧪 Running Tests

To run the test suite:

```bash
php artisan test
```

## 📦 Directory Structure

-   `app/Http/Controllers/API`: API Endpoint logic
-   `routes/api.php`: API Route definitions
-   `database/migrations`: Database schema definitions

## 📝 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
