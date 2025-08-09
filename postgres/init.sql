-- Create the table for transactions
CREATE TABLE transactions (
    correlation_id uuid PRIMARY KEY,
    amount DECIMAL NOT NULL,
    processor VARCHAR(10) NOT NULL,
    created_at TIMESTAMP NOT NULL
);

-- Create an index on the timestamp for faster summary queries
CREATE INDEX idx_transactions_created_at ON transactions(created_at);